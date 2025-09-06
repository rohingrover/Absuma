<?php
session_start();


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in. Please log in first.']);
    exit();
}

require '../db_connection.php';

// Check if user has permission to add locations
$allowed_roles = ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Insufficient permissions.']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Handle AJAX request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_location') {
    try {
        
        $location_name = trim($_POST['location_name']);
        
        // Validate required fields
        if (empty($location_name)) {
            echo json_encode(['success' => false, 'message' => 'Location name is required.']);
            exit();
        }
        
        // Check if location already exists
        $check_stmt = $pdo->prepare("SELECT id FROM location WHERE location = ?");
        $check_stmt->execute([$location_name]);
        
        if ($check_stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Location already exists.']);
            exit();
        }
        
        // Insert new location
        $stmt = $pdo->prepare("
            INSERT INTO location (location, updated_by, updated_at) 
            VALUES (?, ?, NOW())
        ");
        
        $stmt->execute([
            $location_name,
            $_SESSION['user_id']
        ]);
        
        $location_id = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Location added successfully!',
            'location_id' => $location_id,
            'location_name' => $location_name
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error adding location: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
}
?>
