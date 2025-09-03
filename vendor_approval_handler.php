<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Set response content type
header('Content-Type: application/json');

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON data');
    }
    
    $vendorId = $input['vendor_id'] ?? null;
    $action = $input['action'] ?? null;
    $reason = $input['reason'] ?? '';
    
    // Validate input
    if (!$vendorId || !is_numeric($vendorId)) {
        throw new Exception('Invalid vendor ID');
    }
    
    if (!in_array($action, ['approve', 'reject'])) {
        throw new Exception('Invalid action');
    }
    
    if ($action === 'reject' && empty(trim($reason))) {
        throw new Exception('Rejection reason is required');
    }
    
    // Check if vendor exists and is in pending status
    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$vendorId]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vendor) {
        throw new Exception('Vendor not found');
    }
    
    if ($vendor['status'] !== 'pending') {
        throw new Exception('Vendor is not in pending status. Current status: ' . $vendor['status']);
    }
    
    $pdo->beginTransaction();
    
    try {
        if ($action === 'approve') {
            // Update vendor status to active
            $updateStmt = $pdo->prepare("
                UPDATE vendors 
                SET status = 'active', 
                    updated_at = NOW(),
                    approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$_SESSION['user_id'], $vendorId]);
            
            // Log the approval activity
            $logStmt = $pdo->prepare("
                INSERT INTO user_activity_logs 
                (user_id, activity_type, activity_description, ip_address, user_agent, created_at) 
                VALUES (?, 'vendor_approval', ?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $_SESSION['user_id'],
                "Approved vendor: {$vendor['company_name']} (Code: {$vendor['vendor_code']})",
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $message = 'Vendor approved successfully';
            $newStatus = 'active';
            
        } else { // reject
            // Update vendor status to rejected
            $updateStmt = $pdo->prepare("
                UPDATE vendors 
                SET status = 'rejected', 
                    updated_at = NOW(),
                    rejected_by = ?,
                    rejected_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$_SESSION['user_id'], trim($reason), $vendorId]);
            
            // Log the rejection activity
            $logStmt = $pdo->prepare("
                INSERT INTO user_activity_logs 
                (user_id, activity_type, activity_description, ip_address, user_agent, created_at) 
                VALUES (?, 'vendor_rejection', ?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $_SESSION['user_id'],
                "Rejected vendor: {$vendor['company_name']} (Code: {$vendor['vendor_code']}) - Reason: {$reason}",
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $message = 'Vendor rejected successfully';
            $newStatus = 'rejected';
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'vendor_id' => $vendorId,
            'new_status' => $newStatus,
            'vendor_name' => $vendor['company_name']
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>