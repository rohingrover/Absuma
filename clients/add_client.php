<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// Role-based access control - Only Manager level and above can access
$user_role = $_SESSION['role'];
$allowed_roles = ['manager1', 'manager2', 'admin', 'superadmin'];

if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. You don't have permission to add clients. Manager level access required.";
    header("Location: dashboard.php");
    exit();
}

// Define role hierarchy (higher number = more permissions)
$role_hierarchy = [
    'staff' => 1,
    'l1_supervisor' => 2,
    'l2_supervisor' => 3,
    'manager1' => 4,
    'manager2' => 4,
    'admin' => 5,
    'superadmin' => 6
];

// Function to check if user has permission for a specific role level
if (!function_exists('hasRoleAccess')) {
    function hasRoleAccess($required_role, $user_role, $role_hierarchy) {
        return $role_hierarchy[$user_role] >= $role_hierarchy[$required_role];
    }
}

// Initialize variables
$error = '';
$success = '';
$formData = [
    'client_name' => '',
    'contact_person' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'city' => '',
    'state' => '',
    'pincode' => '',
    'gst_number' => '',
    'pan_number' => '',
    'business_type' => '',
    'credit_limit' => '',
    'payment_terms' => ''
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $formData = [
        'client_name' => trim($_POST['client_name']),
        'contact_person' => trim($_POST['contact_person']),
        'email' => trim($_POST['email']),
        'phone' => trim($_POST['phone']),
        'address' => trim($_POST['address']),
        'city' => trim($_POST['city']),
        'state' => trim($_POST['state']),
        'pincode' => trim($_POST['pincode']),
        'gst_number' => trim($_POST['gst_number']),
        'pan_number' => trim($_POST['pan_number']),
        'business_type' => $_POST['business_type'] ?? '',
        'credit_limit' => $_POST['credit_limit'] ?? '',
        'payment_terms' => $_POST['payment_terms'] ?? ''
    ];

    try {
        // Validate required fields
        $errors = [];
        
        if (empty($formData['client_name'])) {
            $errors[] = "Client name is required";
        }
        
        if (empty($formData['contact_person'])) {
            $errors[] = "Contact person name is required";
        }
        
        if (empty($formData['email'])) {
            $errors[] = "Email is required";
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }
        
        if (empty($formData['phone'])) {
            $errors[] = "Phone number is required";
        } elseif (!preg_match('/^[6-9]\d{9}$/', $formData['phone'])) {
            $errors[] = "Invalid phone number format (should be 10 digits starting with 6-9)";
        }
        
        if (!empty($formData['gst_number']) && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $formData['gst_number'])) {
            $errors[] = "Invalid GST number format";
        }
        
        if (!empty($formData['pan_number']) && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $formData['pan_number'])) {
            $errors[] = "Invalid PAN number format";
        }

        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        } else {
            // Check for duplicate email or phone
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE email = ? OR phone = ?");
            $stmt->execute([$formData['email'], $formData['phone']]);
            
            if ($stmt->fetch()) {
                $error = "A client with this email or phone number already exists";
            } else {
                // Insert new client
                $sql = "INSERT INTO clients (client_name, contact_person, email, phone, address, city, state, pincode, gst_number, pan_number, business_type, credit_limit, payment_terms, created_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $formData['client_name'],
                    $formData['contact_person'],
                    $formData['email'],
                    $formData['phone'],
                    $formData['address'],
                    $formData['city'],
                    $formData['state'],
                    $formData['pincode'],
                    $formData['gst_number'],
                    $formData['pan_number'],
                    $formData['business_type'],
                    $formData['credit_limit'],
                    $formData['payment_terms'],
                    $_SESSION['user_id']
                ]);
                
                $_SESSION['success'] = "Client added successfully!";
                header("Location: manage_clients.php");
                exit();
            }
        }
    } catch (Exception $e) {
        $error = "Error adding client: " . $e->getMessage();
    }
}

// Display success/error messages from session
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Client - Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'teal-600': '#0d9488',
                        'teal-700': '#0f766e',
                        'teal-500': '#14b8a6',
                        'teal-50': '#f0fdfa',
                        'teal-100': '#ccfbf1',
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #f0fdfa 0%, #e6fffa 100%);
        }
        
        .shadow-soft {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .card-hover-effect {
            transition: all 0.3s ease;
        }
        
        .card-hover-effect:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
        }
        
        .form-grid {
            @apply grid grid-cols-1 md:grid-cols-2 gap-6;
        }
        
        .form-field {
            @apply space-y-2;
        }
        
        .input-field {
            @apply w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 transition-colors;
        }
        
        .btn-primary {
            @apply bg-teal-600 hover:bg-teal-700 text-white px-6 py-3 rounded-lg font-medium transition-all duration-300 hover:shadow-lg hover:-translate-y-0.5;
        }
        
        .btn-secondary {
            @apply bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg font-medium transition-all duration-300;
        }
        
        .nav-active {
            background: white;
            color: #0d9488;
        }
        
        .nav-item {
            transition: all 0.2s ease;
        }
        
        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out forwards;
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
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen flex">
        <!-- Sidebar Navigation -->
        <?php include '../sidebar_navigation.php' ?>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
                            <i class="fas fa-user-plus text-teal-600"></i>
                            Add New Client
                        </h1>
                        <p class="text-gray-600 text-sm mt-1">Create a new client profile for the transportation management system</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div class="text-right">
                            <div class="text-sm text-gray-500">User Role</div>
                            <div class="font-medium text-teal-600 capitalize"><?= str_replace('_', ' ', $user_role) ?></div>
                        </div>
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input
                                type="text"
                                placeholder="Search"
                                class="pl-10 pr-10 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm"
                            />
                            <i class="fas fa-microphone absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm cursor-pointer hover:text-teal-600"></i>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content Area -->
            <main class="flex-1 p-6 overflow-auto">
                <!-- Alert Messages -->
                <?php if ($success): ?>
                    <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg animate-fade-in">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-check-circle text-green-600"></i>
                            <div><?= $success ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg animate-fade-in">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-exclamation-circle text-red-600"></i>
                            <div><?= $error ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <!-- General Information -->
                    <div class="bg-white rounded-lg shadow-soft overflow-hidden card-hover-effect">
                        <div class="bg-teal-600 px-6 py-4">
                            <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                                <i class="fas fa-info-circle"></i> General Information
                            </h2>
                        </div>
                        <div class="p-6">
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="client_name" class="block text-sm font-medium text-gray-700">
                                        Client/Company Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" name="client_name" id="client_name" required 
                                           value="<?= htmlspecialchars($formData['client_name']) ?>"
                                           class="input-field"
                                           placeholder="Enter client or company name">
                                </div>
                                
                                <div class="form-field">
                                    <label for="contact_person" class="block text-sm font-medium text-gray-700">
                                        Contact Person <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" name="contact_person" id="contact_person" required 
                                           value="<?= htmlspecialchars($formData['contact_person']) ?>"
                                           class="input-field"
                                           placeholder="Primary contact person name">
                                </div>
                                
                                <div class="form-field">
                                    <label for="business_type" class="block text-sm font-medium text-gray-700">Business Type</label>
                                    <select name="business_type" id="business_type" class="input-field">
                                        <option value="">Select business type</option>
                                        <option value="manufacturer" <?= $formData['business_type'] == 'manufacturer' ? 'selected' : '' ?>>Manufacturer</option>
                                        <option value="distributor" <?= $formData['business_type'] == 'distributor' ? 'selected' : '' ?>>Distributor</option>
                                        <option value="retailer" <?= $formData['business_type'] == 'retailer' ? 'selected' : '' ?>>Retailer</option>
                                        <option value="exporter" <?= $formData['business_type'] == 'exporter' ? 'selected' : '' ?>>Exporter</option>
                                        <option value="importer" <?= $formData['business_type'] == 'importer' ? 'selected' : '' ?>>Importer</option>
                                        <option value="logistics" <?= $formData['business_type'] == 'logistics' ? 'selected' : '' ?>>Logistics</option>
                                        <option value="other" <?= $formData['business_type'] == 'other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                                
                                <div class="form-field">
                                    <label for="credit_limit" class="block text-sm font-medium text-gray-700">Credit Limit (₹)</label>
                                    <input type="number" name="credit_limit" id="credit_limit" step="0.01" min="0"
                                           value="<?= htmlspecialchars($formData['credit_limit']) ?>"
                                           class="input-field"
                                           placeholder="Enter credit limit">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="bg-white rounded-lg shadow-soft overflow-hidden card-hover-effect">
                        <div class="bg-teal-600 px-6 py-4">
                            <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                                <i class="fas fa-address-book"></i> Contact Information
                            </h2>
                        </div>
                        <div class="p-6">
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="email" class="block text-sm font-medium text-gray-700">
                                        Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" name="email" id="email" required 
                                           value="<?= htmlspecialchars($formData['email']) ?>"
                                           class="input-field"
                                           placeholder="client@example.com">
                                </div>
                                
                                <div class="form-field">
                                    <label for="phone" class="block text-sm font-medium text-gray-700">
                                        Phone Number <span class="text-red-500">*</span>
                                    </label>
                                    <input type="tel" name="phone" id="phone" required 
                                           value="<?= htmlspecialchars($formData['phone']) ?>"
                                           class="input-field"
                                           placeholder="10-digit mobile number"
                                           pattern="[6-9][0-9]{9}"
                                           maxlength="10">
                                </div>
                                
                                <div class="form-field md:col-span-2">
                                    <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                                    <textarea name="address" id="address" rows="3" 
                                              class="input-field"
                                              placeholder="Complete business address"><?= htmlspecialchars($formData['address']) ?></textarea>
                                </div>
                                
                                <div class="form-field">
                                    <label for="city" class="block text-sm font-medium text-gray-700">City</label>
                                    <input type="text" name="city" id="city" 
                                           value="<?= htmlspecialchars($formData['city']) ?>"
                                           class="input-field"
                                           placeholder="City name">
                                </div>
                                
                                <div class="form-field">
                                    <label for="state" class="block text-sm font-medium text-gray-700">State</label>
                                    <input type="text" name="state" id="state" 
                                           value="<?= htmlspecialchars($formData['state']) ?>"
                                           class="input-field"
                                           placeholder="State name">
                                </div>
                                
                                <div class="form-field">
                                    <label for="pincode" class="block text-sm font-medium text-gray-700">Pincode</label>
                                    <input type="text" name="pincode" id="pincode" 
                                           value="<?= htmlspecialchars($formData['pincode']) ?>"
                                           class="input-field"
                                           placeholder="6-digit pincode"
                                           pattern="[0-9]{6}"
                                           maxlength="6">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Business Information -->
                    <div class="bg-white rounded-lg shadow-soft overflow-hidden card-hover-effect">
                        <div class="bg-teal-600 px-6 py-4">
                            <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                                <i class="fas fa-briefcase"></i> Business Information
                            </h2>
                        </div>
                        <div class="p-6">
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="gst_number" class="block text-sm font-medium text-gray-700">GST Number</label>
                                    <input type="text" name="gst_number" id="gst_number" 
                                           value="<?= htmlspecialchars($formData['gst_number']) ?>"
                                           class="input-field"
                                           placeholder="15-digit GST number"
                                           pattern="^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$"
                                           maxlength="15">
                                </div>
                                
                                <div class="form-field">
                                    <label for="pan_number" class="block text-sm font-medium text-gray-700">PAN Number</label>
                                    <input type="text" name="pan_number" id="pan_number" 
                                           value="<?= htmlspecialchars($formData['pan_number']) ?>"
                                           class="input-field"
                                           placeholder="10-character PAN number"
                                           pattern="^[A-Z]{5}[0-9]{4}[A-Z]{1}$"
                                           maxlength="10">
                                </div>
                                
                                <div class="form-field">
                                    <label for="payment_terms" class="block text-sm font-medium text-gray-700">Payment Terms</label>
                                    <select name="payment_terms" id="payment_terms" class="input-field">
                                        <option value="">Select payment terms</option>
                                        <option value="advance" <?= $formData['payment_terms'] == 'advance' ? 'selected' : '' ?>>100% Advance</option>
                                        <option value="net_7" <?= $formData['payment_terms'] == 'net_7' ? 'selected' : '' ?>>Net 7 Days</option>
                                        <option value="net_15" <?= $formData['payment_terms'] == 'net_15' ? 'selected' : '' ?>>Net 15 Days</option>
                                        <option value="net_30" <?= $formData['payment_terms'] == 'net_30' ? 'selected' : '' ?>>Net 30 Days</option>
                                        <option value="net_60" <?= $formData['payment_terms'] == 'net_60' ? 'selected' : '' ?>>Net 60 Days</option>
                                        <option value="net_90" <?= $formData['payment_terms'] == 'net_90' ? 'selected' : '' ?>>Net 90 Days</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="bg-white rounded-lg shadow-soft p-6 card-hover-effect">
                        <div class="flex flex-col sm:flex-row gap-4 justify-end">
                            <a href="manage_clients.php" class="btn-secondary text-center">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-save mr-2"></i>Add Client
                            </button>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <script>
        // Format GST number input
        document.getElementById('gst_number').addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });
        
        // Format PAN number input
        document.getElementById('pan_number').addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });
        
        // Phone number validation
        document.getElementById('phone').addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '').substring(0, 10);
        });
        
        // Pincode validation
        document.getElementById('pincode').addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '').substring(0, 6);
        });
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const requiredFields = ['client_name', 'contact_person', 'email', 'phone'];
            let isValid = true;
            
            requiredFields.forEach(field => {
                const input = document.getElementById(field);
                if (!input.value.trim()) {
                    input.classList.add('border-red-500', 'ring-red-500');
                    isValid = false;
                } else {
                    input.classList.remove('border-red-500', 'ring-red-500');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    </script>
</body>
</html>