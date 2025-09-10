<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Handle phone number update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_phone'])) {
    $phone_number = trim($_POST['phone_number']);
    
    // Validate Indian phone number (10 digits)
    if (empty($phone_number)) {
        $error = "Phone number is required";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone_number)) {
        $error = "Please enter a valid 10-digit Indian phone number";
    } else {
        try {
            // Add +91 prefix
            $full_phone = '+91' . $phone_number;
            
            $stmt = $pdo->prepare("UPDATE users SET phone_number = ? WHERE id = ?");
            $stmt->execute([$full_phone, $_SESSION['user_id']]);
            
            $success = "Phone number updated successfully!";
        } catch (Exception $e) {
            $error = "Error updating phone number: " . $e->getMessage();
        }
    }
}

// Get user data from session and database
$user = [
    'id' => $_SESSION['user_id'] ?? null,
    'full_name' => $_SESSION['full_name'] ?? 'Unknown',
    'username' => 'Unknown',
    'email' => 'Not provided',
    'role' => $_SESSION['role'] ?? 'unknown',
    'phone_number' => null
];

// Fetch user details from database if user ID exists
if ($user['id']) {
    try {
        $stmt = $pdo->prepare("SELECT username, email, phone_number FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $user_result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_result) {
            $user['username'] = $user_result['username'] ?? 'Unknown';
            $user['email'] = $user_result['email'] ?? 'Not provided';
            $user['phone_number'] = $user_result['phone_number'];
        }
    } catch (Exception $e) {
        // Database fetch failed, but continue with session data
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile - ABSUMA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --absuma-red: #e53e3e;
            --absuma-teal: #0d9488;
        }
        .shadow-soft { box-shadow: 0 2px 15px -3px rgba(0, 0, 0, 0.07), 0 10px 20px -2px rgba(0, 0, 0, 0.04); }
        .card-hover-effect { transition: all 0.3s ease; }
        .card-hover-effect:hover { transform: translateY(-2px); box-shadow: 0 4px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Sidebar Navigation -->
        <?php include 'sidebar_navigation.php'; ?>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
        <!-- Header -->
        <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">User Profile</h1>
                    <p class="text-sm text-gray-600 mt-1">Manage your account information and phone number</p>
                </div>
                <div class="flex items-center space-x-4"></div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 p-6 overflow-auto">
            <div class="max-w-7xl mx-auto">
                <div class="space-y-6">
                        <!-- User Information -->
                        <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                            <h2 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-user text-absuma-teal mr-3"></i>
                                Account Information
                            </h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="space-y-1">
                                    <label class="block text-sm font-medium text-gray-700">Full Name</label>
                                    <p class="text-gray-900 font-medium"><?= htmlspecialchars($user['full_name'] ?? 'N/A') ?></p>
                                </div>
                                <div class="space-y-1">
                                    <label class="block text-sm font-medium text-gray-700">Username</label>
                                    <p class="text-gray-900 font-medium"><?= htmlspecialchars($user['username'] ?? 'N/A') ?></p>
                                </div>
                                <div class="space-y-1">
                                    <label class="block text-sm font-medium text-gray-700">Email</label>
                                    <p class="text-gray-900 font-medium"><?= htmlspecialchars($user['email'] ?? 'Not provided') ?></p>
                                </div>
                                <div class="space-y-1">
                                    <label class="block text-sm font-medium text-gray-700">Role</label>
                                    <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full bg-teal-100 text-teal-800">
                                        <?= ucfirst(str_replace('_', ' ', $user['role'] ?? 'unknown')) ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Phone Number Update -->
                        <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                            <h2 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-phone text-absuma-teal mr-3"></i>
                                Phone Number
                            </h2>
                            
                            <?php if (isset($error)): ?>
                                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
                                    <i class="fas fa-exclamation-circle mr-2"></i>
                                    <?= htmlspecialchars($error) ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (isset($success)): ?>
                                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    <?= htmlspecialchars($success) ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST" class="space-y-6">
                                <div>
                                    <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-2">
                                        Phone Number <span class="text-red-500">*</span>
                                    </label>
                                    <div class="flex">
                                        <span class="inline-flex items-center px-3 text-sm text-gray-500 bg-gray-50 border border-r-0 border-gray-300 rounded-l-lg">
                                            +91
                                        </span>
                                        <input type="tel" 
                                               id="phone_number" 
                                               name="phone_number" 
                                               maxlength="10"
                                               pattern="[0-9]{10}"
                                               placeholder="9876543210"
                                               value="<?= htmlspecialchars(str_replace('+91', '', $user['phone_number'] ?? '')) ?>"
                                               class="flex-1 px-3 py-2 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                               required>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">Enter 10-digit Indian mobile number (without +91)</p>
                                </div>
                                
                                <div class="flex items-center justify-between pt-4 border-t border-gray-200">
                                    <div class="text-sm text-gray-600 flex items-center">
                                        <i class="fas fa-info-circle mr-2 text-teal-500"></i>
                                        Your phone number will be used for WhatsApp notifications
                                    </div>
                                    <button type="submit" 
                                            name="update_phone"
                                            class="bg-teal-600 hover:bg-teal-700 text-white px-6 py-2 rounded-lg font-medium transition-colors">
                                        <i class="fas fa-save mr-2"></i>
                                        Update Phone Number
                                    </button>
                                </div>
                            </form>
                        </div>
                </div>
            </div>
        </main>
        </div>
    </div>

    <script>
        // Format phone number input
        document.addEventListener('DOMContentLoaded', function() {
            const phoneInput = document.getElementById('phone_number');
            if (phoneInput) {
                phoneInput.addEventListener('input', function(e) {
                    // Remove any non-digit characters
                    let value = e.target.value.replace(/\D/g, '');
                    
                    // Limit to 10 digits
                    if (value.length > 10) {
                        value = value.substring(0, 10);
                    }
                    
                    e.target.value = value;
                });
            }
        });
    </script>
</body>
</html>