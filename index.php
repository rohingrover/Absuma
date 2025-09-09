<?php
session_start();
require 'db_connection.php';

// Handle login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Invalid username or password.";
    }
}

// Handle signup request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['signup'])) {
    try {
        $pdo->beginTransaction();

        $signup_data = [
            'username' => trim($_POST['username']),
            'password' => password_hash(trim($_POST['password']), PASSWORD_DEFAULT),
            'full_name' => trim($_POST['full_name']),
            'email' => trim($_POST['email']),
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'pending'
        ];

        // Validation
        $errors = [];
        if (empty($signup_data['username'])) {
            $errors[] = "Username is required";
        }
        if (empty($signup_data['password'])) {
            $errors[] = "Password is required";
        }
        if (empty($signup_data['full_name'])) {
            $errors[] = "Full name is required";
        }
        if (empty($signup_data['email']) || !filter_var($signup_data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email address is required";
        }

        // Check for duplicate username
        $usernameCheck = $pdo->prepare("SELECT id FROM signup_requests WHERE username = ?");
        $usernameCheck->execute([$signup_data['username']]);
        if ($usernameCheck->rowCount() > 0) {
            $errors[] = "Username already requested";
        }

        // Check for duplicate email
        $emailCheck = $pdo->prepare("SELECT id FROM signup_requests WHERE email = ?");
        $emailCheck->execute([$signup_data['email']]);
        if ($emailCheck->rowCount() > 0) {
            $errors[] = "Email already requested";
        }

        if (!empty($errors)) {
            throw new Exception(implode("<br>", $errors));
        }

        // Insert into signup_requests table
        $sql = "INSERT INTO signup_requests (username, password, full_name, email, created_at, status) VALUES (:username, :password, :full_name, :email, :created_at, :status)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($signup_data);

        $pdo->commit();
        $success = "Signup request submitted successfully. Please wait for admin approval.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Absuma Logistics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="gradient-bg min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full space-y-8 p-6">
        <!-- Alert Messages -->
        <?php if (!empty($success)): ?>
            <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded-lg animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-400 mr-3"></i>
                    <p class="text-green-700"><?= htmlspecialchars($success) ?></p>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="bg-red-50 border-l-4 border-red-400 p-4 rounded-lg animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-400 mr-3"></i>
                    <p class="text-red-700"><?= htmlspecialchars($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <div class="bg-white rounded-xl shadow-soft p-8 card-hover-effect">
            <div class="text-center">
                <h2 class="text-3xl font-bold text-gray-900 mb-2">Welcome Back</h2>
                <p class="text-sm text-gray-600">Sign in to your account</p>
            </div>
            <form method="POST" class="mt-8 space-y-6" novalidate>
                <input type="hidden" name="login" value="1">
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                    <div class="mt-1 relative">
                        <input type="text" id="username" name="username" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="Enter username">
                        <i class="fas fa-user absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <div class="mt-1 relative">
                        <input type="password" id="password" name="password" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="Enter password">
                        <i class="fas fa-lock absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>
                <div>
                    <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                        <i class="fas fa-sign-in-alt mr-2"></i> Sign In
                    </button>
                </div>
            </form>
            <div class="mt-4 text-center">
                <p class="text-sm text-gray-600">Don't have an account? <a href="#signup" class="text-teal-600 hover:text-teal-800 font-medium" onclick="showSignupForm()">Sign Up</a></p>
            </div>
        </div>

        <!-- Signup Form -->
        <div id="signupForm" class="bg-white rounded-xl shadow-soft p-8 card-hover-effect hidden">
            <div class="text-center">
                <h2 class="text-3xl font-bold text-gray-900 mb-2">Create Account</h2>
                <p class="text-sm text-gray-600">Sign up for an account</p>
            </div>
            <form method="POST" class="mt-8 space-y-6" novalidate>
                <input type="hidden" name="signup" value="1">
                <div>
                    <label for="signup_username" class="block text-sm font-medium text-gray-700">Username *</label>
                    <div class="mt-1 relative">
                        <input type="text" id="signup_username" name="username" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="Enter username">
                        <i class="fas fa-user absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>
                <div>
                    <label for="signup_password" class="block text-sm font-medium text-gray-700">Password *</label>
                    <div class="mt-1 relative">
                        <input type="password" id="signup_password" name="password" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="Enter password">
                        <i class="fas fa-lock absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>
                <div>
                    <label for="signup_full_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                    <div class="mt-1 relative">
                        <input type="text" id="signup_full_name" name="full_name" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="Enter full name">
                        <i class="fas fa-user-tie absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>
                <div>
                    <label for="signup_email" class="block text-sm font-medium text-gray-700">Email *</label>
                    <div class="mt-1 relative">
                        <input type="email" id="signup_email" name="email" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="Enter email">
                        <i class="fas fa-envelope absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>
                <div>
                    <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                        <i class="fas fa-user-plus mr-2"></i> Sign Up
                    </button>
                </div>
            </form>
            <div class="mt-4 text-center">
                <p class="text-sm text-gray-600">Already have an account? <a href="#login" class="text-teal-600 hover:text-teal-800 font-medium" onclick="showLoginForm()">Sign In</a></p>
            </div>
        </div>

    </div>

    <script>
        function showSignupForm() {
            document.querySelector('.card-hover-effect').classList.add('hidden');
            document.getElementById('signupForm').classList.remove('hidden');
        }

        function showLoginForm() {
            document.getElementById('signupForm').classList.add('hidden');
            document.querySelector('.card-hover-effect').classList.remove('hidden');
        }
    </script>
</body>
</html>