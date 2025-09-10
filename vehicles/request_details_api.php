<?php
// request_details_api.php - API to fetch detailed request information for approval system

session_start();
require '../auth_check.php';
require '../db_connection.php';

header('Content-Type: application/json');

// Check if user has approval permissions
$user_role = $_SESSION['role'];
$allowed_roles = ['manager1', 'manager2', 'admin', 'superadmin'];

if (!in_array($user_role, $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

if (!isset($_GET['id']) || !isset($_GET['type'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$request_id = $_GET['id'];
$request_type = $_GET['type'];

try {
    $request_data = null;
    
    switch ($request_type) {
        case 'vehicle':
            $request_data = getVehicleRequestDetails($pdo, $request_id);
            break;
        case 'vendor':
            $request_data = getVendorRequestDetails($pdo, $request_id);
            break;
        case 'trip':
            $request_data = getTripRequestDetails($pdo, $request_id);
            break;
        case 'booking':
            $request_data = getBookingRequestDetails($pdo, $request_id);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid request type']);
            exit();
    }
    
    if ($request_data) {
        echo json_encode(['success' => true, 'request' => $request_data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function getVehicleRequestDetails($pdo, $request_id) {
    $stmt = $pdo->prepare("
        SELECT 
            vcr.*,
            v.vehicle_number as current_vehicle_number,
            u.full_name as requested_by_name,
            u.role as requester_role,
            approver.full_name as approved_by_name
        FROM vehicle_change_requests vcr 
        LEFT JOIN vehicles v ON vcr.vehicle_id = v.id 
        LEFT JOIN users u ON vcr.requested_by = u.id 
        LEFT JOIN users approver ON vcr.approved_by = approver.id
        WHERE vcr.id = ?
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        return null;
    }
    
    // Add additional details for vehicle requests
    if ($request['vehicle_id']) {
        // Get current financing details if it's an update request
        $financingStmt = $pdo->prepare("SELECT * FROM vehicle_financing WHERE vehicle_id = ?");
        $financingStmt->execute([$request['vehicle_id']]);
        $financing = $financingStmt->fetch(PDO::FETCH_ASSOC);
        $request['current_financing'] = $financing;
    }
    
    return $request;
}

function getVendorRequestDetails($pdo, $request_id) {
    // Placeholder for vendor request details
    // This would query a vendor_change_requests table when implemented
    return [
        'id' => $request_id,
        'request_type' => 'create',
        'proposed_data' => json_encode([
            'vendor_name' => 'Sample Vendor',
            'contact' => '9876543210',
            'service_type' => 'Transportation'
        ]),
        'current_data' => null,
        'reason' => 'New vendor registration',
        'created_at' => date('Y-m-d H:i:s'),
        'requested_by_name' => 'Sample User',
        'requester_role' => 'l2_supervisor'
    ];
}

function getTripRequestDetails($pdo, $request_id) {
    // Placeholder for trip request details
    // This would query a trip_change_requests table when implemented
    return [
        'id' => $request_id,
        'request_type' => 'create',
        'proposed_data' => json_encode([
            'origin' => 'Delhi',
            'destination' => 'Mumbai',
            'trip_date' => date('Y-m-d', strtotime('+1 week'))
        ]),
        'current_data' => null,
        'reason' => 'New trip request',
        'created_at' => date('Y-m-d H:i:s'),
        'requested_by_name' => 'Sample User',
        'requester_role' => 'l2_supervisor'
    ];
}

function getBookingRequestDetails($pdo, $request_id) {
    // Placeholder for booking request details
    // This would query a booking_change_requests table when implemented
    return [
        'id' => $request_id,
        'request_type' => 'create',
        'proposed_data' => json_encode([
            'client_name' => 'ABC Company',
            'service' => 'Transportation Service',
            'booking_date' => date('Y-m-d', strtotime('+3 days'))
        ]),
        'current_data' => null,
        'reason' => 'New booking request',
        'created_at' => date('Y-m-d H:i:s'),
        'requested_by_name' => 'Sample User',
        'requester_role' => 'l2_supervisor'
    ];
}
?>