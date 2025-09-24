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
        // Get form data with safe defaults
        $vehicle_number = trim($_POST['vehicle_number'] ?? '');
        $driver_name = trim($_POST['driver_name'] ?? '');
        $owner_name = trim($_POST['owner_name'] ?? '');
        $make_model = trim($_POST['make_model'] ?? '');
        $manufacturing_year = $_POST['manufacturing_year'] ?? '';
        $gvw = trim($_POST['gvw'] ?? '');
        $is_financed = isset($_POST['is_financed']) ? 1 : 0;
        $bank_name = trim($_POST['bank_name'] ?? '');
        $emi_amount = trim($_POST['emi_amount'] ?? '');
        $loan_amount = trim($_POST['loan_amount'] ?? '');
        $interest_rate = trim($_POST['interest_rate'] ?? '');
        $loan_tenure_months = trim($_POST['loan_tenure_months'] ?? '');
        $loan_start_date = $_POST['loan_start_date'] ?? '';
        $loan_end_date = $_POST['loan_end_date'] ?? '';
        
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
                gvw, is_financed, bank_name, emi_amount, loan_amount, interest_rate,
                loan_tenure_months, loan_start_date, loan_end_date, current_status, 
                approval_status, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', 'pending', ?, NOW())
        ");
        
        $stmt->execute([
            $vehicle_number, $driver_name, $owner_name, $make_model, $manufacturing_year,
            $gvw, $is_financed, $bank_name, $emi_amount, $loan_amount, $interest_rate,
            $loan_tenure_months, $loan_start_date, $loan_end_date, $_SESSION['user_id']
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
