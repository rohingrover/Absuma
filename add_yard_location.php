<?php
    session_start();
    require 'auth_check.php';
    require 'db_connection.php';
    
    $success = '';
    $error = '';
    
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
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Check for duplicate yard name in same location
            $duplicateCheck = $pdo->prepare("
                SELECT id FROM yard_locations 
                WHERE yard_name = ? AND location_id = ? AND deleted_at IS NULL
            ");
            $duplicateCheck->execute([$yard_data['yard_name'], $yard_data['location_id']]);
            if ($duplicateCheck->rowCount() > 0) {
                throw new Exception('Yard with this name already exists in the selected location');
            }
            
            // Check for duplicate yard code if provided
            if (!empty($yard_data['yard_code'])) {
                $codeCheck = $pdo->prepare("
                    SELECT id FROM yard_locations 
                    WHERE yard_code = ? AND deleted_at IS NULL
                ");
                $codeCheck->execute([$yard_data['yard_code']]);
                if ($codeCheck->rowCount() > 0) {
                    throw new Exception('Yard code already exists');
                }
            }
            
            // Insert yard location
            $sql = "INSERT INTO yard_locations (
                yard_name, location_id, yard_code, yard_type, contact_person, 
                phone_number, email, address, is_active, created_at
            ) VALUES (
                :yard_name, :location_id, :yard_code, :yard_type, :contact_person,
                :phone_number, :email, :address, 1, :created_at
            )";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($yard_data);
            
            $pdo->commit();
            $success = "Yard location added successfully!";
            
            // Reset form
            $_POST = [];
            
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
    
    // Form data for repopulation
    $formData = [
        'yard_name' => $_POST['yard_name'] ?? '',
        'location_id' => $_POST['location_id'] ?? '',
        'yard_code' => $_POST['yard_code'] ?? '',
        'yard_type' => $_POST['yard_type'] ?? '',
        'contact_person' => $_POST['contact_person'] ?? '',
        'phone_number' => $_POST['phone_number'] ?? '',
        'email' => $_POST['email'] ?? '',
        'address' => $_POST['address'] ?? ''
    ];
    ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Yard Location - Absuma Logistics Fleet Management</title>
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
        .absuma-gradient {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
        }
        .card-hover-effect {
            transition: all 0.3s ease;
        }
        .card-hover-effect:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="gradient-bg">

    <!-- Header -->
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
                                <a href="add_yard_location.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium absuma-gradient text-white rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center"></i>Add Yard Location
                                </a>
                                <a href="manage_yard_locations.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Yards
                                </a>
                                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-tachometer-alt w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Dashboard
                                </a>
                            </nav>
                        </div>
                    </div>
                </aside>


    <!-- Main Content -->
    <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        <!-- Alert Messages -->
        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6" role="alert">
                <div class="flex">
                    <div class="py-1">
                        <svg class="fill-current h-6 w-6 text-green-500 mr-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <path d="M2.93 17.07A10 10 0 1 1 17.07 2.93 10 10 0 0 1 2.93 17.07zm12.73-1.41A8 8 0 1 0 4.34 4.34a8 8 0 0 0 11.32 11.32zM9 11V9h2v6H9v-4zm0-6h2v2H9V5z"/>
                        </svg>
                    </div>
                    <div><?php echo $success; ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6" role="alert">
                <div class="flex">
                    <div class="py-1">
                        <svg class="fill-current h-6 w-6 text-red-500 mr-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <path d="M2.93 17.07A10 10 0 1 1 17.07 2.93 10 10 0 0 1 2.93 17.07zm12.73-1.41A8 8 0 1 0 4.34 4.34a8 8 0 0 0 11.32 11.32zM9 11V9h2v6H9v-4zm0-6h2v2H9V5z"/>
                        </svg>
                    </div>
                    <div><?php echo $error; ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form Card -->
        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">
                    <i class="fas fa-warehouse text-blue-600 mr-2"></i>Yard Location Details
                </h2>
            </div>

            <form method="POST" class="p-6">
                <!-- Basic Information Section -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <!-- Yard Name -->
                    <div>
                        <label for="yard_name" class="block text-sm font-medium text-gray-700 mb-2">
                            Yard Name *
                        </label>
                        <input type="text" id="yard_name" name="yard_name" required
                               value="<?php echo htmlspecialchars($formData['yard_name']); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                               placeholder="e.g., JNPT Container Terminal">
                    </div>

                    <!-- Location -->
                    <div>
                        <label for="location_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Main Location *
                        </label>
                        <select id="location_id" name="location_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select Location</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location['id']; ?>" 
                                        <?php echo ($formData['location_id'] == $location['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($location['location']); ?>
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
                               value="<?php echo htmlspecialchars($formData['yard_code']); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                               placeholder="e.g., JNPT-CT">
                    </div>

                    <!-- Yard Type -->
                    <div>
                        <label for="yard_type" class="block text-sm font-medium text-gray-700 mb-2">
                            Yard Type *
                        </label>
                        <select id="yard_type" name="yard_type" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select Type</option>
                            <option value="terminal" <?php echo ($formData['yard_type'] == 'terminal') ? 'selected' : ''; ?>>Container Terminal</option>
                            <option value="container_yard" <?php echo ($formData['yard_type'] == 'container_yard') ? 'selected' : ''; ?>>Container Yard</option>
                            <option value="freight_station" <?php echo ($formData['yard_type'] == 'freight_station') ? 'selected' : ''; ?>>Freight Station</option>
                            <option value="warehouse" <?php echo ($formData['yard_type'] == 'warehouse') ? 'selected' : ''; ?>>Warehouse</option>
                            <option value="other" <?php echo ($formData['yard_type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>

                <!-- Contact Information Section -->
                <div class="border-t border-gray-200 pt-6 mb-8">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">
                        <i class="fas fa-phone text-green-600 mr-2"></i>Contact Information
                        <span class="text-sm text-gray-500 font-normal">(Optional)</span>
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Contact Person -->
                        <div>
                            <label for="contact_person" class="block text-sm font-medium text-gray-700 mb-2">
                                Contact Person
                            </label>
                            <input type="text" id="contact_person" name="contact_person"
                                   value="<?php echo htmlspecialchars($formData['contact_person']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="Contact person name">
                        </div>

                        <!-- Phone Number -->
                        <div>
                            <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-2">
                                Phone Number
                            </label>
                            <input type="tel" id="phone_number" name="phone_number"
                                   value="<?php echo htmlspecialchars($formData['phone_number']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="+91 9876543210">
                        </div>

                        <!-- Email -->
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                Email Address
                            </label>
                            <input type="email" id="email" name="email"
                                   value="<?php echo htmlspecialchars($formData['email']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="contact@example.com">
                        </div>

                        <!-- Address -->
                        <div>
                            <label for="address" class="block text-sm font-medium text-gray-700 mb-2">
                                Address
                            </label>
                            <textarea id="address" name="address" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="Full address of the yard"><?php echo htmlspecialchars($formData['address']); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="border-t border-gray-200 pt-6">
                    <div class="flex justify-end space-x-3">
                        <button type="reset" 
                                class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <i class="fas fa-undo mr-2"></i>Reset
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-blue-600 border border-transparent rounded-md shadow-sm text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <i class="fas fa-save mr-2"></i>Add Yard Location
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Help Section -->
        <div class="mt-8 bg-blue-50 border-l-4 border-blue-400 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h4 class="text-sm font-medium text-blue-800">About Yard Locations</h4>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Yard locations represent specific terminals, warehouses, or yards within a main location</li>
                            <li>For example: "JNPT Container Terminal" is a yard within "NHAVA SHEVA" location</li>
                            <li>This helps in precise location tracking for container movements</li>
                            <li>Yard codes are optional but recommended for easy identification</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

   

    <script>
        // Form validation and enhancements
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const yardNameInput = document.getElementById('yard_name');
            const yardCodeInput = document.getElementById('yard_code');
            
            // Auto-generate yard code from yard name
            yardNameInput.addEventListener('input', function() {
                if (!yardCodeInput.value) {
                    const name = this.value.trim();
                    const code = name
                        .replace(/[^a-zA-Z0-9\s]/g, '')
                        .replace(/\s+/g, '-')
                        .toUpperCase()
                        .substring(0, 10);
                    yardCodeInput.value = code;
                }
            });
            
            // Phone number formatting
            const phoneInput = document.getElementById('phone_number');
            phoneInput.addEventListener('input', function() {
                let value = this.value.replace(/\D/g, '');
                if (value.length > 10) value = value.substring(0, 10);
                this.value = value;
            });
            
            // Form submission confirmation
            form.addEventListener('submit', function(e) {
                const yardName = yardNameInput.value.trim();
                const locationId = document.getElementById('location_id').value;
                const yardType = document.getElementById('yard_type').value;
                
                if (!yardName || !locationId || !yardType) {
                    e.preventDefault();
                    alert('Please fill all required fields marked with *');
                    return false;
                }
                
                // Show loading state
                const submitBtn = document.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Adding...';
            });
        });
    </script>
</body>
</html>