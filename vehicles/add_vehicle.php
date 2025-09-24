<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// Role-based access control
$user_role = $_SESSION['role'];
$allowed_roles = ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin'];

if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. You don't have permission to add vehicles.";
    header("Location: ../dashboard.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Get form data
        $vehicle_number = trim($_POST['vehicle_number']);
        $driver_name = trim($_POST['driver_name']);
        $owner_name = trim($_POST['owner_name']);
        $make_model = trim($_POST['make_model']);
        $manufacturing_year = $_POST['manufacturing_year'];
        $gvw = trim($_POST['gvw']);
        $driver_license = trim($_POST['driver_license']);
        $driver_phone = trim($_POST['driver_phone']);
        $driver_address = trim($_POST['driver_address']);
        $insurance_number = trim($_POST['insurance_number']);
        $insurance_expiry = $_POST['insurance_expiry'];
        $puc_number = trim($_POST['puc_number']);
        $puc_expiry = $_POST['puc_expiry'];
        $fitness_expiry = $_POST['fitness_expiry'];
        $permit_expiry = $_POST['permit_expiry'];
        $rc_number = trim($_POST['rc_number']);
        $rc_expiry = $_POST['rc_expiry'];
        $notes = trim($_POST['notes']);
        
        // Validation
        if (empty($vehicle_number)) {
            throw new Exception("Vehicle number is required");
        }
        
        if (empty($driver_name)) {
            throw new Exception("Driver name is required");
        }
        
        if (empty($owner_name)) {
            throw new Exception("Owner name is required");
        }
        
        // Check if vehicle number already exists
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_number = ?");
        $stmt->execute([$vehicle_number]);
        if ($stmt->fetch()) {
            throw new Exception("Vehicle number already exists");
        }
        
        // Insert vehicle
        $stmt = $pdo->prepare("
            INSERT INTO vehicles (
                vehicle_number, driver_name, owner_name, make_model, manufacturing_year,
                gvw, driver_license, driver_phone, driver_address, insurance_number,
                insurance_expiry, puc_number, puc_expiry, fitness_expiry, permit_expiry,
                rc_number, rc_expiry, notes, current_status, approval_status, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', 'pending', ?, NOW())
        ");
        
        $stmt->execute([
            $vehicle_number, $driver_name, $owner_name, $make_model, $manufacturing_year,
            $gvw, $driver_license, $driver_phone, $driver_address, $insurance_number,
            $insurance_expiry, $puc_number, $puc_expiry, $fitness_expiry, $permit_expiry,
            $rc_number, $rc_expiry, $notes, $_SESSION['user_id']
        ]);
        
        $success = "Vehicle added successfully! It will be reviewed for approval.";
        
        // Redirect to manage vehicles page
        header("Location: manage.php?success=" . urlencode($success));
        exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// If we reach here, there was an error - redirect back to add form
header("Location: add.php?error=" . urlencode($error));
exit();
?>
