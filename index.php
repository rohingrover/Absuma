<?php
session_start();
require 'db_connection.php';

// Redirect to dashboard if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid username or password";
        }
    } else {
        $error = "Please enter both username and password";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absuma Logistics - Fleet Management Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #e53e3e;
            --primary-light: #fed7d7;
            --secondary: #2d3748;
            --text: #2b2d42;
            --text-light: #8d99ae;
            --background: #f7fafc;
            --white: #ffffff;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --radius: 0.75rem;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 1rem;
        }

        .login-container {
            width: 100%;
            max-width: 440px;
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, #c53030 100%);
            color: var(--white);
            padding: 2rem 1.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .logo-container {
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .logo-text {
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .logo-tagline {
            font-size: 0.875rem;
            opacity: 0.95;
            font-weight: 500;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
        }

        .services-list {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .service-item {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        .system-title {
            font-size: 1rem;
            margin-top: 1rem;
            opacity: 0.9;
            font-weight: 600;
        }

        .login-content {
            padding: 2rem 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text);
            font-size: 0.875rem;
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            z-index: 1;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.75rem;
            border: 2px solid #e2e8f0;
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 1rem;
            transition: var(--transition);
            background: #f8fafc;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--primary);
            background: var(--white);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .btn {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, var(--primary) 0%, #c53030 100%);
            color: var(--white);
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(229, 62, 62, 0.4);
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn:active {
            transform: translateY(0);
        }

        .alert {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-error {
            background: #fed7d7;
            color: #c53030;
            border: 1px solid #feb2b2;
        }

        .footer-text {
            text-align: center;
            margin-top: 1.5rem;
            color: var(--text-light);
            font-size: 0.875rem;
        }

        @media (max-width: 480px) {
            .login-container {
                max-width: 100%;
                margin: 1rem;
            }
            
            .login-header {
                padding: 1.5rem 1rem;
            }
            
            .logo-text {
                font-size: 1.5rem;
            }
            
            .services-list {
                gap: 0.5rem;
            }
            
            .service-item {
                font-size: 0.6875rem;
                padding: 0.2rem 0.5rem;
            }
        }

        /* Loading animation for button */
        .btn.loading {
            pointer-events: none;
        }

        .btn.loading::after {
            content: '';
            width: 1rem;
            height: 1rem;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 0.5rem;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo-container">
                <div class="logo-text">ABSUMA Logistics India Pvt. Ltd.</div>
                <div class="logo-tagline">Comprehensive Logistics Solutions</div>
                <div class="services-list">
                    <span class="service-item">Transportation</span>
                    <span class="service-item">Fumigation</span>
                    <span class="service-item">FHAT</span>
                    <span class="service-item">WPM</span>
                </div>
                <div class="system-title">Fleet Management System</div>
            </div>
        </div>
        
        <div class="login-content"> 
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> 
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="loginForm">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-group">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="username" name="username" required placeholder="Enter your username">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" required placeholder="Enter your password">
                    </div>
                </div>
                
                <button type="submit" class="btn" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i> 
                    <span>Sign In</span>
                </button>
            </form>
            
            <div class="footer-text">
                Secure access to your fleet management dashboard
            </div>
        </div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
            btn.querySelector('span').textContent = 'Signing In...';
        });

        // Auto-focus username field
        document.getElementById('username').focus();

        // Add enter key support
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('loginForm').submit();
            }
        });
    </script>
</body>
</html>