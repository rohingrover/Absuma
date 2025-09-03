<?php
session_start();

// Security: Regenerate session ID to prevent session fixation attacks
session_regenerate_id(true);

// Store user info for logging before destroying session
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? null;
$full_name = $_SESSION['full_name'] ?? null;

// Optional: Log the logout activity
if ($user_id && isset($pdo)) {
    try {
        require_once 'db_connection.php';
        
        // Log logout activity (if you have an activity log table)
        $logStmt = $pdo->prepare("
            INSERT INTO user_activity_logs (user_id, activity_type, activity_description, ip_address, user_agent, created_at) 
            VALUES (?, 'logout', ?, ?, ?, NOW())
        ");
        
        $activity_description = "User logged out: " . $full_name;
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $logStmt->execute([$user_id, $activity_description, $ip_address, $user_agent]);
    } catch (Exception $e) {
        // Silently fail logging - don't prevent logout
        error_log("Logout logging failed: " . $e->getMessage());
    }
}

// Clear all session variables
$_SESSION = array();

// Delete the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear any remember-me cookies if they exist
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

// Set a success message for the login page
session_start();
$_SESSION['logout_success'] = "You have been successfully logged out.";

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Redirect to login page
header("Location: index.php");
exit();
?>