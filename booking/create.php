<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// Check if user has L2 supervisor access or above
$user_role = $_SESSION['role'];
$allowed_roles = ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin'];

if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. You don't have permission to create bookings.";
    header("Location: dashboard.php");
    exit();
}

// Set page title and subtitle for header component
$page_title = 'Create New Booking';
$page_subtitle = 'Container Booking Management';

$success_message = '';
$error_message = '';

// Function to determine if booking needs approval
function needsApproval($user_role, $booking_id, $pdo) {
    // Check if booking ID is system generated (starts with AB-YYYY-)
    $current_year = date('Y');
    $system_prefix = "AB-{$current_year}-";
    $is_system_generated = strpos($booking_id, $system_prefix) === 0;
    
    switch ($user_role) {
        case 'l2_supervisor':
            // L2 supervisor bookings always need approval from manager or above
            return true;
            
        case 'manager1':
            // Manager1 needs approval only if booking ID is manually typed (not system generated)
            return !$is_system_generated;
            
        case 'manager2':
            // Manager2 needs approval only if booking ID is manually typed (not system generated)
            return !$is_system_generated;
            
        case 'admin':
        case 'superadmin':
            // Admin and superadmin never need approval
            return false;
            
        default:
            return true;
    }
}

// Function to determine required approval role
function getRequiredApprovalRole($user_role) {
    switch ($user_role) {
        case 'l2_supervisor':
            return 'manager1'; // L2 needs manager1 or above
            
        case 'manager1':
            return 'manager2'; // Manager1 needs manager2 or above
            
        case 'manager2':
            return 'admin'; // Manager2 needs admin or above
            
        default:
            return null;
    }
}

// Function to determine initial booking status
function getInitialStatus($user_role, $booking_id, $pdo) {
    // Normalize to existing workflow statuses used across the app
    // Approvals (if any) are tracked via requires_approval/required_approval_role fields
    return 'pending';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("Form submission received");
    error_log("POST data: " . print_r($_POST, true));
    try {
        $client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $no_of_containers = isset($_POST['no_of_containers']) ? (int)$_POST['no_of_containers'] : 0;
        $movement_type = isset($_POST['movement_type']) ? trim($_POST['movement_type']) : '';
        $container_types = $_POST['container_types'] ?? [];
        $container_numbers = $_POST['container_numbers'] ?? [];
        $container_from_location_ids = $_POST['container_from_location_ids'] ?? [];
        $container_to_location_ids = $_POST['container_to_location_ids'] ?? [];
        $same_locations_all = isset($_POST['same_locations_all']) ? 1 : 0;
        
        // Handle PDF file upload
        $booking_receipt_pdf = null;
        $upload_error = '';
        
        
        
        if (isset($_FILES['booking_receipt_pdf']) && $_FILES['booking_receipt_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
            
            if ($_FILES['booking_receipt_pdf']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../Uploads/booking_docs/';
                
                // Create directory if it doesn't exist
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0755, true)) {
                        $upload_error = 'Failed to create upload directory';
                    }
                }
                
                if (empty($upload_error)) {
                    $file_extension = strtolower(pathinfo($_FILES['booking_receipt_pdf']['name'], PATHINFO_EXTENSION));
                    
                    if ($file_extension === 'pdf') {
                        $file_name = 'booking_receipt_' . time() . '_' . uniqid() . '.pdf';
                        $file_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($_FILES['booking_receipt_pdf']['tmp_name'], $file_path)) {
                            $booking_receipt_pdf = $file_name;
                        } else {
                            $upload_error = 'Failed to move uploaded file';
                        }
                    } else {
                        $upload_error = 'Only PDF files are allowed';
                    }
                }
            } else {
                $upload_error = 'File upload error: ' . $_FILES['booking_receipt_pdf']['error'];
            }
        }
        
        // Normalize optional FK fields to NULL when empty
        // Handle both regular locations (loc_X) and yard locations (yard_X)
        $from_location_id = null;
        $from_location_type = null;
        if (isset($_POST['from_location_id']) && $_POST['from_location_id'] !== '') {
            $from_location_value = $_POST['from_location_id'];
            if (strpos($from_location_value, 'loc_') === 0) {
                // Regular location: extract the ID
                $from_location_id = (int)substr($from_location_value, 4);
                $from_location_type = 'location';
            } elseif (strpos($from_location_value, 'yard_') === 0) {
                // Yard location: store the yard ID directly
                $yard_id = (int)substr($from_location_value, 5);
                $from_location_id = $yard_id;
                $from_location_type = 'yard';
            } else {
                // Fallback: try to cast as integer (legacy support)
                $from_location_id = (int)$from_location_value;
                $from_location_type = 'location'; // Assume regular location for legacy
            }
        }
        
        $to_location_id = null;
        $to_location_type = null;
        if (isset($_POST['to_location_id']) && $_POST['to_location_id'] !== '') {
            $to_location_value = $_POST['to_location_id'];
            if (strpos($to_location_value, 'loc_') === 0) {
                // Regular location: extract the ID
                $to_location_id = (int)substr($to_location_value, 4);
                $to_location_type = 'location';
            } elseif (strpos($to_location_value, 'yard_') === 0) {
                // Yard location: store the yard ID directly
                $yard_id = (int)substr($to_location_value, 5);
                $to_location_id = $yard_id;
                $to_location_type = 'yard';
            } else {
                // Fallback: try to cast as integer (legacy support)
                $to_location_id = (int)$to_location_value;
                $to_location_type = 'location'; // Assume regular location for legacy
            }
        }
            
        // Read booking id from form
        $booking_id = trim($_POST['booking_id'] ?? '');
        
        // Debug: Log location values
        error_log("From location: ID=$from_location_id, Type=$from_location_type");
        error_log("To location: ID=$to_location_id, Type=$to_location_type");
        
        // Validate booking ID
        if (empty($booking_id)) {
            throw new Exception("Booking ID is required");
        }
        
        // Validate movement type
        if (empty($movement_type)) {
            throw new Exception("Movement Type is required");
        }
        
        $valid_movement_types = ['import', 'export', 'port_yard_movement', 'domestic_movement'];
        if (!in_array($movement_type, $valid_movement_types)) {
            throw new Exception("Invalid movement type selected");
        }
        
        // Check if booking ID already exists
        $check_stmt = $pdo->prepare("SELECT id FROM bookings WHERE booking_id = ?");
        $check_stmt->execute([$booking_id]);
        if ($check_stmt->fetch()) {
            throw new Exception("Booking ID already exists. Please use a different ID.");
        }
        
        // Determine status and approval requirements
        $initial_status = getInitialStatus($user_role, $booking_id, $pdo);
        $requires_approval = needsApproval($user_role, $booking_id, $pdo);
        $required_approval_role = $requires_approval ? getRequiredApprovalRole($user_role) : null;
        
        // Start transaction for data integrity
        $pdo->beginTransaction();

        // Detect if booking_containers table exists (graceful degradation)
        $hasContainersTable = false;
        try {
            $existsStmt = $pdo->query("SHOW TABLES LIKE 'booking_containers'");
            if ($existsStmt->fetch()) { $hasContainersTable = true; }
        } catch (Exception $e) { $hasContainersTable = false; }
        
        try {
            // Insert main booking record (handle legacy schemas with container_type/container_number)
            $columnsStmt = $pdo->query("SHOW COLUMNS FROM bookings");
            $existingColumns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

            $insertColumns = ['booking_id','client_id','no_of_containers','movement_type','from_location_id','from_location_type','to_location_id','to_location_type','status','created_by','updated_by'];
            $placeholders = ['?','?','?','?','?','?','?','?','?','?','?'];
            $params = [
                $booking_id,
                $client_id,
                $no_of_containers,
                $movement_type,
                $from_location_id,
                $from_location_type,
                $to_location_id,
                $to_location_type,
                $initial_status,
                $_SESSION['user_id'],
                $_SESSION['user_id']
            ];
            
            // Add booking receipt PDF if uploaded
            if ($booking_receipt_pdf) {
                $insertColumns[] = 'booking_receipt_pdf';
                $placeholders[] = '?';
                $params[] = $booking_receipt_pdf;
            }

            // Add approval-related fields if they exist
            if (in_array('requires_approval', $existingColumns, true)) {
                $insertColumns[] = 'requires_approval';
                $placeholders[] = '?';
                $params[] = $requires_approval ? 1 : 0;
            }
            
            if (in_array('required_approval_role', $existingColumns, true)) {
                $insertColumns[] = 'required_approval_role';
                $placeholders[] = '?';
                $params[] = $required_approval_role;
            }

            // Handle legacy columns
            if (in_array('container_type', $existingColumns, true)) {
                $insertColumns[] = 'container_type';
                $placeholders[] = '?';
                $params[] = '';
            }
            if (in_array('container_number', $existingColumns, true)) {
                $insertColumns[] = 'container_number';
                $placeholders[] = '?';
                $params[] = '';
            }

            $sql = 'INSERT INTO bookings (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            error_log("SQL: " . $sql);
            error_log("Params: " . print_r($params, true));
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $booking_db_id = $pdo->lastInsertId();
            error_log("Booking created with ID: " . $booking_db_id);
            
            // Insert individual container records (only if table exists)
            if ($hasContainersTable && $no_of_containers > 0) {
                // Detect optional per-container location columns
                $bcColumns = [];
                try {
                    $bcColsStmt = $pdo->query("SHOW COLUMNS FROM booking_containers");
                    $bcColumns = $bcColsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
                } catch (Exception $e) { $bcColumns = []; }

                $hasPerContainerLocations = in_array('from_location_id', $bcColumns, true) && in_array('to_location_id', $bcColumns, true);

                // Detect if container_type allows NULL
                $containerTypeAllowsNull = false;
                try {
                    $colStmt = $pdo->query("SHOW COLUMNS FROM booking_containers LIKE 'container_type'");
                    $colInfo = $colStmt->fetch(PDO::FETCH_ASSOC);
                    if ($colInfo && isset($colInfo['Null'])) {
                        $containerTypeAllowsNull = strtoupper($colInfo['Null']) === 'YES';
                    }
                } catch (Exception $e) { $containerTypeAllowsNull = false; }

                if ($hasPerContainerLocations) {
                    $container_stmt_any = $pdo->prepare("
                        INSERT INTO booking_containers (booking_id, container_sequence, container_type, container_number_1, container_number_2, from_location_id, from_location_type, to_location_id, to_location_type) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                } else {
                    $container_stmt_any = $pdo->prepare("
                        INSERT INTO booking_containers (booking_id, container_sequence, container_type, container_number_1, container_number_2) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                }

                $number_index = 0;
                $insertCount = (int)$no_of_containers; // Use the actual number of containers
                for ($i = 0; $i < $insertCount; $i++) {
                    $sequence = $i + 1;

                    $rawType = isset($container_types[$i]) ? trim($container_types[$i]) : '';
                    $typeValid = ($rawType === '20ft' || $rawType === '40ft');
                    $container_type = $typeValid ? $rawType : null;

                    // Determine container numbers based on size (single number for both 20ft and 40ft)
                    $number1 = null; $number2 = null;
                    if ($container_type === '20ft' || $container_type === '40ft') {
                        $number1 = isset($container_numbers[$number_index]) && $container_numbers[$number_index] !== '' ? trim($container_numbers[$number_index]) : null;
                        $number2 = null;
                        $number_index += 1;
                    }

                    // Determine per-container locations
                    $container_from_id = null;
                    $container_from_type = null;
                    $container_to_id = null;
                    $container_to_type = null;
                    if ($hasPerContainerLocations) {
                        // Handle container from location
                        if (isset($container_from_location_ids[$i]) && $container_from_location_ids[$i] !== '') {
                            $container_from_value = $container_from_location_ids[$i];
                            if (strpos($container_from_value, 'loc_') === 0) {
                                $container_from_id = (int)substr($container_from_value, 4);
                                $container_from_type = 'location';
                            } elseif (strpos($container_from_value, 'yard_') === 0) {
                                $yard_id = (int)substr($container_from_value, 5);
                                $container_from_id = $yard_id;
                                $container_from_type = 'yard';
                            } else {
                                $container_from_id = (int)$container_from_value;
                                $container_from_type = 'location';
                            }
                        }
                        
                        // Handle container to location
                        if (isset($container_to_location_ids[$i]) && $container_to_location_ids[$i] !== '') {
                            $container_to_value = $container_to_location_ids[$i];
                            if (strpos($container_to_value, 'loc_') === 0) {
                                $container_to_id = (int)substr($container_to_value, 4);
                                $container_to_type = 'location';
                            } elseif (strpos($container_to_value, 'yard_') === 0) {
                                $yard_id = (int)substr($container_to_value, 5);
                                $container_to_id = $yard_id;
                                $container_to_type = 'yard';
                            } else {
                                $container_to_id = (int)$container_to_value;
                                $container_to_type = 'location';
                            }
                        }
                        
                        if ($same_locations_all) {
                            // Override per-container locations with global From/To when checkbox is checked
                            $container_from_id = $from_location_id;
                            $container_to_id = $to_location_id;
                        } else {
                            // Fallback: if per-container missing, use global
                            if ($container_from_id === null) { $container_from_id = $from_location_id; }
                            if ($container_to_id === null) { $container_to_id = $to_location_id; }
                        }
                    }

                    // Decide whether to insert this row
                    $hasAnyData = $typeValid || $number1 !== null || $number2 !== null || ($hasPerContainerLocations && ($container_from_id !== null || $container_to_id !== null));
                    if (!$hasAnyData) {
                        continue; // nothing to save for this sequence
                    }

                    // If type is missing and DB doesn't allow NULL, default to '20ft' to persist locations
                    if ($container_type === null && !$containerTypeAllowsNull) {
                        $container_type = '20ft';
                    }

                    if ($hasPerContainerLocations) {
                        $container_stmt_any->execute([
                            $booking_db_id,
                            $sequence,
                            $container_type,
                            $number1,
                            $number2,
                            $container_from_id,
                            $container_from_type,
                            $container_to_id,
                            $container_to_type
                        ]);
                    } else {
                        $container_stmt_any->execute([
                            $booking_db_id,
                            $sequence,
                            $container_type,
                            $number1,
                            $number2
                        ]);
                    }
                }
            }
            
            // Commit transaction
            $pdo->commit();
            
            // Create appropriate success message based on status
            if ($initial_status === 'pending_approval') {
                $success_message = "Booking created successfully! Booking ID: " . $booking_id . 
                                 " - Status: Pending Approval (Requires approval from " . ucfirst(str_replace('_', ' ', $required_approval_role)) . " or above)";
            } else {
                $success_message = "Booking created successfully! Booking ID: " . $booking_id . 
                                 " - Status: Ready for L2 Supervisor to work on";
            }
            
            if ($booking_receipt_pdf) {
                $success_message .= " | PDF uploaded: " . htmlspecialchars($booking_receipt_pdf);
            }
            if ($upload_error) {
                $success_message .= " | Upload warning: " . htmlspecialchars($upload_error);
            }
            
            
            // Clear form data
            $_POST = array();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollback();
            $error_message = "Error creating booking: " . $e->getMessage();
        }
        
    } catch (Exception $e) {
        $error_message = "Error creating booking: " . $e->getMessage();
    }
}

// Get clients for dropdown
$clients_stmt = $pdo->query("SELECT id, client_name, client_code FROM clients WHERE status = 'active' ORDER BY client_name");
$clients = $clients_stmt->fetchAll();

// Get locations for autocomplete
$locations_stmt = $pdo->query("SELECT id, location FROM location ORDER BY location");
$location_results = $locations_stmt->fetchAll();
foreach ($location_results as $loc) {
    $locations[] = [
        'id' => 'loc_' . $loc['id'],
        'location' => $loc['location'] . ' (Location)',
        'source' => 'location',
        'original_name' => $loc['location']
    ];
}

// Get from yard_locations table (only active ones)
try {
    $yard_stmt = $pdo->query("SELECT id, yard_name FROM yard_locations WHERE is_active = 1 ORDER BY yard_name");
    $yard_results = $yard_stmt->fetchAll();
    foreach ($yard_results as $yard) {
        $locations[] = [
            'id' => 'yard_' . $yard['id'],
            'location' => $yard['yard_name'] . ' (Yard)',
            'source' => 'yard',
            'original_name' => $yard['yard_name']
        ];
    }
} catch (PDOException $e) {
    // yard_locations table might not exist, continue without it
}

// Function to generate next booking ID
function generateNextBookingId($pdo) {
    try {
        $current_year = date('Y');
        $prefix = "AB-{$current_year}-";
        
        // Get the last booking ID with this prefix
        $stmt = $pdo->prepare("SELECT booking_id FROM bookings WHERE booking_id LIKE ? ORDER BY booking_id DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last_booking = $stmt->fetch();
        
        if ($last_booking) {
            // Extract the number part and increment
            $last_number = intval(substr($last_booking['booking_id'], strlen($prefix)));
            $next_number = $last_number + 1;
        } else {
            // First booking of the year
            $next_number = 1;
        }
        
        $booking_id = $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);
        
        // Validate the generated booking ID
        if (empty($booking_id) || strlen($booking_id) < 8) {
            throw new Exception("Generated booking ID is invalid: " . $booking_id);
        }
        
        return $booking_id;
    } catch (Exception $e) {
        error_log("Error in generateNextBookingId: " . $e->getMessage());
        throw new Exception("Failed to generate booking ID: " . $e->getMessage());
    }
}

// Handle AJAX request for generating booking ID
if (isset($_GET['action']) && $_GET['action'] === 'generate_booking_id') {
    header('Content-Type: application/json');
    try {
        $booking_id = generateNextBookingId($pdo);
        echo json_encode(['booking_id' => $booking_id]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Booking - Fleet Management</title>
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
                        'teal-200': '#99f6e4',
                        'teal-300': '#5eead4',
                        'teal-400': '#2dd4bf',
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
            transform: translateY(-2px);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animation-delay-100 { animation-delay: 0.1s; }
        .animation-delay-200 { animation-delay: 0.2s; }
        .animation-delay-300 { animation-delay: 0.3s; }
        
        .form-input {
            @apply w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 transition-all duration-200;
        }
        
        .form-label {
            @apply block text-sm font-semibold text-gray-700 mb-2;
        }
        
        .btn-primary {
            @apply bg-teal-600 hover:bg-teal-700 text-white font-semibold py-3 px-6 rounded-lg transition-all duration-200 shadow-md hover:shadow-lg;
        }
        
        .btn-secondary {
            @apply bg-gray-500 hover:bg-gray-600 text-white font-semibold py-3 px-6 rounded-lg transition-all duration-200 shadow-md hover:shadow-lg;
        }
        
        .autocomplete-container {
            position: relative;
        }
        
        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-top: none;
            border-radius: 0 0 0.75rem 0.75rem;
            max-height: 250px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .autocomplete-item {
            padding: 0.875rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .autocomplete-item:hover {
            background: linear-gradient(135deg, #f0fdfa 0%, #e6fffa 100%);
            transform: translateX(4px);
        }
        
        .autocomplete-item:last-child {
            border-bottom: none;
        }
        
        .autocomplete-item-icon {
            width: 8px;
            height: 8px;
            background: #0d9488;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .autocomplete-item-text {
            flex: 1;
            font-weight: 500;
        }
        
        .autocomplete-no-results {
            padding: 1rem;
            text-align: center;
            color: #6b7280;
            font-style: italic;
        }
        
        /* Enhanced form styling */
        .form-section {
            @apply bg-white rounded-xl shadow-soft p-6 border border-gray-100;
        }
        
        .form-section-header {
            @apply flex items-center gap-3 mb-6 pb-4 border-b border-gray-200;
        }
        
        .form-section-title {
            @apply text-lg font-semibold text-gray-800 flex items-center;
        }
        
        .form-section-icon {
            @apply w-8 h-8 bg-teal-600 text-white rounded-lg flex items-center justify-center;
        }
        
        .container-field {
            @apply bg-white rounded-lg p-4 border border-gray-200 shadow-sm transition-all duration-200 hover:shadow-md;
        }
        
        .container-number {
            @apply w-10 h-10 bg-teal-600 text-white rounded-full flex items-center justify-center font-semibold shadow-md;
        }
        
        .input-enhanced {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background-color: white;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        
        .input-enhanced:focus {
            outline: none;
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1), 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transform: scale(1.02);
        }
        
        .btn-enhanced {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: none;
            cursor: pointer;
        }
        
        .btn-enhanced:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .btn-primary-enhanced {
            background-color: #0d9488;
            color: white;
        }
        
        .btn-primary-enhanced:hover {
            background-color: #0f766e;
        }
        
        .btn-secondary-enhanced {
            background-color: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary-enhanced:hover {
            background-color: #e5e7eb;
            border-color: #9ca3af;
        }
        
        /* Fix dropdown styling */
        select.input-enhanced {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 40px;
        }
        
        .nav-active {
            background: white;
            color: #0d9488 !important;
        }
        
        .nav-item {
            transition: all 0.2s ease;
        }
        
        .nav-item:hover:not(.nav-active) {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .generate-btn {
            transition: all 0.3s ease;
        }
        
        .generate-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -5px rgba(13, 148, 136, 0.4);
        }
        
        .booking-id-success {
            @apply border-green-500 bg-green-50;
            animation: successPulse 0.6s ease-out;
        }
        
        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }

        /* Approval workflow info styling */
        .approval-info {
            @apply bg-gradient-to-r from-blue-50 to-blue-100 border border-blue-200 rounded-lg p-4 mt-4;
        }
        
        .approval-info-icon {
            @apply w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center;
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen flex">
        <!-- Sidebar Navigation -->
        <?php include '../sidebar_navigation.php'; ?>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col text-gray-900">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Create Booking</h1>
                        <p class="text-sm text-gray-600 mt-1">Create new container booking requests</p>
                    </div>
                    <div class="flex items-center space-x-4"></div>
                </div>
            </header>

            <!-- Approval Workflow Information Banner -->
            <div class="mx-6 mt-6 approval-info">
                <div class="flex items-start gap-3">
                    <div class="approval-info-icon">
                        <i class="fas fa-info text-sm"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-blue-800 mb-2">Booking Approval Workflow</h3>
                        <div class="text-xs text-blue-700 space-y-1">
                            <?php if ($user_role === 'l2_supervisor'): ?>
                                <p><strong>As L2 Supervisor:</strong> All your bookings will require approval from Manager1 or above before being processed.</p>
                            <?php elseif ($user_role === 'manager1'): ?>
                                <p><strong>As Manager1:</strong> System-generated booking IDs don't need approval. Manually typed booking IDs require approval from Manager2 or above.</p>
                            <?php elseif ($user_role === 'manager2'): ?>
                                <p><strong>As Manager2:</strong> System-generated booking IDs don't need approval. Manually typed booking IDs require approval from Admin or above.</p>
                            <?php else: ?>
                                <p><strong>As <?= ucfirst(str_replace('_', ' ', $user_role)) ?>:</strong> Your bookings don't require approval and will be directly available for L2 processing.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($success_message): ?>
                <div class="mx-6 mt-6 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg animate-fade-in">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-check-circle mt-1"></i>
                        <div class="flex-1">
                            <?= htmlspecialchars($success_message) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mx-6 mt-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg animate-fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Main Content -->
            <main class="flex-1 p-6 overflow-auto">
                <div class="max-w-7xl mx-auto">
                    <!-- Page Header -->
                    <div class="bg-white rounded-xl shadow-soft p-6 border-l-4 border-teal-600 mb-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800 mb-2">
                                    <i class="fas fa-shipping-fast text-teal-600 mr-3"></i>
                                    Create New Booking
                                </h2>
                                <p class="text-gray-600">Fill in the container booking details below</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="bg-teal-50 p-4 rounded-lg">
                                    <i class="fas fa-clipboard-list text-3xl text-teal-600"></i>
                                </div>
                            </div>
                        </div>
                        
                    </div>

                    <!-- Booking Form -->
                    <div class="form-section fade-in-up">
                        <form method="POST" enctype="multipart/form-data" class="space-y-6">
                            <!-- Booking ID Input Section -->
                            <div class="mt-4 p-4 bg-gradient-to-r from-teal-50 to-teal-100 rounded-lg border border-teal-200">
                                <div class="flex items-center gap-3 mb-4">
                                    <div class="w-10 h-10 bg-teal-600 text-white rounded-full flex items-center justify-center">
                                        <i class="fas fa-hashtag text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-600">Booking ID</p>
                                        <p class="text-xs text-gray-500">Enter manually or generate automatically</p>
                                    </div>
                                </div>
                                
                                <div class="flex gap-3">
                                    <div class="flex-1">
                                        <input type="text" 
                                               name="booking_id" 
                                               id="booking_id" 
                                               class="input-enhanced" 
                                               placeholder="Enter booking ID"
                                               value="<?= htmlspecialchars($_POST['booking_id'] ?? '') ?>"
                                               maxlength="50"
                                               required>
                                    </div>
                                    <button type="button" 
                                            id="generate-booking-id-btn"
                                            class="generate-btn bg-teal-600 hover:bg-teal-700 text-white px-4 py-3 rounded-lg shadow-md flex items-center gap-2">
                                        <i class="fas fa-magic"></i>
                                        <span class="hidden sm:inline">Generate</span>
                                    </button>
                                </div>
                                
                                <!-- Approval Preview Section -->
                                <div id="approval-preview" class="hidden mt-4 p-4 rounded-lg border">
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center">
                                            <i class="fas fa-info text-sm"></i>
                                        </div>
                                        <div class="flex-1">
                                            <h4 class="text-sm font-semibold text-gray-800 mb-1">Booking Status Preview</h4>
                                            <p id="approval-preview-text" class="text-sm text-gray-600"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Basic Information Section -->
                            <div class="form-section-header">
                                <div class="form-section-icon">
                                    <i class="fas fa-info-circle"></i>
                                </div>
                                <h2 class="form-section-title">Basic Information</h2>
                            </div>
                            
                            <!-- Client, Movement Type and Number of Containers -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                                <!-- Client Selection with Autocomplete -->
                                <div>
                                    <label for="client_search" class="form-label">
                                        <i class="fas fa-building text-teal-600 mr-2"></i>Client *
                                    </label>
                                    <div class="relative">
                                        <div class="autocomplete-container">
                                            <input type="text" 
                                                   name="client_search" 
                                                   id="client_search" 
                                                   class="input-enhanced pr-10" 
                                                   placeholder="Type to search clients..."
                                                   autocomplete="off"
                                                   value="<?= htmlspecialchars($_POST['client_search'] ?? '') ?>">
                                            <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                                <i class="fas fa-search text-gray-400"></i>
                                            </div>
                                            <input type="hidden" name="client_id" id="client_id" value="<?= htmlspecialchars($_POST['client_id'] ?? '') ?>">
                                            <div class="autocomplete-dropdown" id="client_dropdown"></div>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Start typing to see client suggestions
                                        </div>
                                    </div>
                                </div>

                                <!-- Movement Type -->
                                <div>
                                    <label for="movement_type" class="form-label">
                                        <i class="fas fa-truck text-teal-600 mr-2"></i>Movement Type *
                                    </label>
                                    <select name="movement_type" id="movement_type" class="input-enhanced" required>
                                        <option value="">Select Movement Type</option>
                                        <option value="import" <?= ($_POST['movement_type'] ?? '') === 'import' ? 'selected' : '' ?>>Import</option>
                                        <option value="export" <?= ($_POST['movement_type'] ?? '') === 'export' ? 'selected' : '' ?>>Export</option>
                                        <option value="port_yard_movement" <?= ($_POST['movement_type'] ?? '') === 'port_yard_movement' ? 'selected' : '' ?>>Port/Yard Movement</option>
                                        <option value="domestic_movement" <?= ($_POST['movement_type'] ?? '') === 'domestic_movement' ? 'selected' : '' ?>>Domestic Movement</option>
                                    </select>
                                    <p class="text-xs text-gray-500 mt-2 flex items-center">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Select the type of container movement
                                    </p>
                                </div>

                                <!-- Number of Containers -->
                                <div>
                                    <label for="no_of_containers" class="form-label">
                                        <i class="fas fa-cubes text-teal-600 mr-2"></i>Number of Containers *
                                    </label>
                                    <input type="number" name="no_of_containers" id="no_of_containers" 
                                           class="input-enhanced" min="1" max="20" required
                                           value="<?= htmlspecialchars($_POST['no_of_containers'] ?? '') ?>">
                                    <p class="text-xs text-gray-500 mt-2 flex items-center">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Maximum 20 containers per booking
                                    </p>
                                </div>
                            </div>

                            <!-- Location Information Section -->
                            <div class="form-section-header">
                                <div class="form-section-icon">
                                    <i class="fas fa-map-marker-alt"></i>
                                </div>
                                <h2 class="form-section-title">Location Information</h2>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- From Location -->
                                <div>
                                    <label for="from_location" class="form-label">
                                        <i class="fas fa-map-marker-alt text-teal-600 mr-2"></i>From Location (Optional)
                                    </label>
                                    <div class="relative">
                                        <div class="autocomplete-container">
                                            <input type="text" name="from_location" id="from_location" 
                                                   class="input-enhanced pr-10" placeholder="Type to search locations..."
                                                   autocomplete="off">
                                            <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                                <i class="fas fa-search text-gray-400"></i>
                                            </div>
                                            <input type="hidden" name="from_location_id" id="from_location_id">
                                            <div class="autocomplete-dropdown" id="from_location_dropdown"></div>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Start typing to see location suggestions
                                        </div>
                                    </div>
                                </div>

                                <!-- To Location -->
                                <div>
                                    <label for="to_location" class="form-label">
                                        <i class="fas fa-map-marker-alt text-teal-600 mr-2"></i>To Location (Optional)
                                    </label>
                                    <div class="relative">
                                        <div class="autocomplete-container">
                                            <input type="text" name="to_location" id="to_location" 
                                                   class="input-enhanced pr-10" placeholder="Type to search locations..."
                                                   autocomplete="off">
                                            <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                                <i class="fas fa-search text-gray-400"></i>
                                            </div>
                                            <input type="hidden" name="to_location_id" id="to_location_id">
                                            <div class="autocomplete-dropdown" id="to_location_dropdown"></div>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Start typing to see location suggestions
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Same for all containers checkbox -->
                            <div class="mt-2 flex items-center gap-3">
                                <input type="checkbox" id="same_locations_all" name="same_locations_all" class="h-4 w-4 text-teal-600 border-gray-300 rounded">
                                <label for="same_locations_all" class="text-sm text-gray-700">Use the same From/To locations for all containers</label>
                            </div>

                            <!-- Location Creation Link -->
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mt-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-info-circle text-blue-600"></i>
                                        <span class="text-sm text-blue-800 font-medium">Location not found?</span>
                                    </div>
                                    <button type="button" 
                                            onclick="openLocationModal()"
                                            class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-md transition-colors duration-200">
                                        <i class="fas fa-plus mr-1"></i>Add New Location
                                    </button>
                                </div>
                            </div>

                            <!-- PDF Upload Section -->
                            <div class="form-section-header">
                                <div class="form-section-icon">
                                    <i class="fas fa-file-pdf"></i>
                                </div>
                                <h2 class="form-section-title">Booking Receipt PDF <span class="text-sm font-normal text-gray-500">(Optional)</span></h2>
                            </div>
                            
                            <div class="bg-white rounded-lg p-4 border border-gray-200 shadow-sm">
                                <div class="flex items-center justify-center w-full">
                                    <div class="w-full">
                                        <label for="booking_receipt_pdf" class="flex flex-col items-center justify-center w-full h-32 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100 transition-colors duration-200">
                                            <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                                <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
                                                <p class="mb-2 text-sm text-gray-500">
                                                    <span class="font-semibold">Click to upload</span> or drag and drop
                                                </p>
                                                <p class="text-xs text-gray-500">PDF files only (MAX. 10MB)</p>
                                            </div>
                                        </label>
                                        <input id="booking_receipt_pdf" name="booking_receipt_pdf" type="file" accept=".pdf" class="mt-2 w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100" />
                                    </div>
                                </div>
                                <div class="mt-3 text-xs text-gray-500 flex items-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Upload the booking receipt PDF document (optional)
                                </div>
                            </div>

                            

                            <!-- Container Details Section (Optional) -->
                            <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm">
                                <!-- Optional Notice for Managers -->
                                <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-info-circle text-blue-600"></i>
                                        <span class="text-sm text-blue-800 font-medium">Container details are optional</span>
                                    </div>
                                    <p class="text-xs text-blue-700 mt-1">You can create a booking with just basic information. Container details can be added later if needed.</p>
                                </div>
                                <div class="flex items-center justify-between mb-6">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-teal-600 text-white rounded-lg flex items-center justify-center">
                                            <i class="fas fa-barcode text-sm"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-lg font-semibold text-gray-900">Container Details <span class="text-sm font-normal text-gray-500">(Optional)</span></h3>
                                            <p class="text-sm text-gray-500">Configure each container with type and numbers - leave empty to skip container details</p>
                                        </div>
                                    </div>
                                    <div class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full" id="container-count-display">
                                        No containers selected
                                    </div>
                                </div>
                                
                                <div id="container-details-container" class="space-y-4">
                                    <div class="text-center text-gray-500 py-8">
                                        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                            <i class="fas fa-cube text-2xl text-gray-400"></i>
                                        </div>
                                        <p class="text-base font-medium mb-1">No containers selected</p>
                                        <p class="text-sm">Select number of containers above to configure container details (optional)</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-6 border border-gray-200">
                                <div class="flex flex-col sm:flex-row gap-4">
                                    <button type="submit" class="group relative overflow-hidden bg-gradient-to-r from-teal-600 to-teal-700 hover:from-teal-700 hover:to-teal-800 text-white font-bold py-3 px-6 rounded-lg transition-all duration-300 transform hover:scale-105 hover:shadow-lg shadow-md flex items-center justify-center text-sm">
                                        <div class="absolute inset-0 bg-gradient-to-r from-white/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                        <div class="relative flex items-center">
                                            <i class="fas fa-shipping-fast mr-2 text-sm group-hover:animate-pulse"></i>
                                            <span class="text-sm">Create Booking</span>
                                        </div>
                                        <div class="absolute inset-0 bg-white/10 transform -skew-x-12 -translate-x-full group-hover:translate-x-full transition-transform duration-700"></div>
                                    </button>
                                    
                                    <a href="dashboard.php" class="group bg-white hover:bg-gray-50 text-gray-700 hover:text-gray-900 font-semibold py-3 px-6 rounded-lg border-2 border-gray-300 hover:border-gray-400 transition-all duration-300 transform hover:scale-105 shadow-md hover:shadow-lg flex items-center justify-center text-sm">
                                        <i class="fas fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform duration-300"></i>
                                        <span>Back to Dashboard</span>
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>
	<script>
    // Prevent unnecessary regenerations
    let lastGeneratedContainerCount = null;
    // Current user role for approval logic
    const userRole = '<?= $user_role ?>';
    
    // Location autocomplete functionality
    const locations = <?= json_encode($locations) ?>;
    console.log('Locations loaded:', locations);
    
    // Function to check if booking ID is system generated
    function isSystemGenerated(bookingId) {
        const currentYear = new Date().getFullYear();
        const systemPrefix = `AB-${currentYear}-`;
        return bookingId.startsWith(systemPrefix);
    }
    
    // Function to preview approval requirements
    function previewApprovalRequirements(bookingId) {
        const previewDiv = document.getElementById('approval-preview');
        const previewText = document.getElementById('approval-preview-text');
        
        // Check if elements exist
        if (!previewDiv || !previewText) {
            console.warn('Approval preview elements not found');
            return;
        }
        
        if (!bookingId || bookingId.trim() === '') {
            previewDiv.classList.add('hidden');
            return;
        }
        
        const isSystemGen = isSystemGenerated(bookingId);
        let message = '';
        let needsApproval = false;
        
        switch (userRole) {
            case 'l2_supervisor':
                needsApproval = true;
                message = 'As L2 Supervisor, this booking will require approval from Manager1 or above before processing.';
                break;
                
            case 'manager1':
                if (!isSystemGen) {
                    needsApproval = true;
                    message = 'Manual booking ID detected. This booking will require approval from Manager2 or above.';
                } else {
                    message = 'System-generated booking ID. No approval required - will be directly available for L2 processing.';
                }
                break;
                
            case 'manager2':
                if (!isSystemGen) {
                    needsApproval = true;
                    message = 'Manual booking ID detected. This booking will require approval from Admin or above.';
                } else {
                    message = 'System-generated booking ID. No approval required - will be directly available for L2 processing.';
                }
                break;
                
            case 'admin':
            case 'superadmin':
                message = 'As ' + userRole.charAt(0).toUpperCase() + userRole.slice(1) + ', no approval required - will be directly available for L2 processing.';
                break;
        }
        
        if (message) {
            previewText.textContent = message;
            previewDiv.classList.remove('hidden');
            
            // Add visual styling based on approval requirement
            if (needsApproval) {
                previewDiv.className = 'mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg';
                previewDiv.querySelector('.w-8').className = 'w-8 h-8 bg-yellow-600 text-white rounded-full flex items-center justify-center';
            } else {
                previewDiv.className = 'mt-4 p-4 bg-green-50 border border-green-200 rounded-lg';
                previewDiv.querySelector('.w-8').className = 'w-8 h-8 bg-green-600 text-white rounded-full flex items-center justify-center';
            }
        }
    }
    
    // Client autocomplete setup function
    function setupClientAutocomplete() {
        console.log('setupClientAutocomplete called');
        const input = document.getElementById('client_search');
        const dropdown = document.getElementById('client_dropdown');
        const hiddenInput = document.getElementById('client_id');
        
        console.log('Elements found:', { input: !!input, dropdown: !!dropdown, hiddenInput: !!hiddenInput });
        
        if (!input || !dropdown || !hiddenInput) {
            console.error('Required elements not found for client autocomplete');
            return;
        }
        
        const allClients = <?= json_encode($clients) ?>;
        console.log('Clients loaded:', allClients);
        let activeIndex = -1;
        let currentActive = null;
        let isDropdownOpen = false;
        
        // Function to render clients in dropdown
        function renderClients(clients) {
            if (clients.length === 0) {
                dropdown.innerHTML = '<div class="p-3 text-gray-500 text-sm">No clients found</div>';
            } else {
                dropdown.innerHTML = clients.map((client, index) => 
                    '<div class="autocomplete-item p-3 cursor-pointer hover:bg-teal-50 border-b border-gray-100 transition-colors" ' +
                    'data-value="' + client.id + '" data-text="' + client.client_name + ' (' + client.client_code + ')">' +
                    '<div class="font-medium text-gray-900">' + client.client_name + '</div>' +
                    '<div class="text-sm text-gray-500">' + client.client_code + '</div>' +
                    '</div>'
                ).join('');
            }
        }
        
        // Function to filter clients based on search query
        function filterClients(query) {
            if (!query || query.trim().length === 0) {
                return allClients;
            }
            
            const searchTerm = query.toLowerCase().trim();
            return allClients.filter(client => 
                client.client_name.toLowerCase().includes(searchTerm) ||
                client.client_code.toLowerCase().includes(searchTerm)
            );
        }
        
        // Show dropdown with all clients initially
        function showDropdown() {
            if (!isDropdownOpen) {
                renderClients(allClients);
                dropdown.style.display = 'block';
                isDropdownOpen = true;
            }
        }
        
        // Hide dropdown
        function hideDropdown() {
            dropdown.style.display = 'none';
            isDropdownOpen = false;
            activeIndex = -1;
        }
          
        input.addEventListener('input', function() {
            const query = this.value;
            if (query.length >= 2) {
                const filteredClients = filterClients(query);
                renderClients(filteredClients);
                dropdown.style.display = 'block';
            } else if (query.length === 0) {
                // Show all clients when field is empty but user typed something
                renderClients(allClients);
                dropdown.style.display = 'block';
            }
        }); 

        // Input change - filter clients
        input.addEventListener('input', function() {
            const query = this.value;
            const filteredClients = filterClients(query);
            renderClients(filteredClients);
            
            // Clear hidden input if search is empty
            if (!query || query.trim().length === 0) {
                hiddenInput.value = '';
            }
            
            activeIndex = -1;
        });
        
        // Dropdown click handler
        dropdown.addEventListener('click', function(e) {
            const item = e.target.closest('.autocomplete-item');
            if (item) {
                const value = item.getAttribute('data-value');
                const text = item.getAttribute('data-text');
                
                input.value = text;
                hiddenInput.value = value;
                hideDropdown();
            }
        });
        
        // Click outside to close dropdown
        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                hideDropdown();
            }
        });
        
        // Initialize dropdown as hidden
        hideDropdown();
    }
    
    function setupAutocomplete(inputId, dropdownId, hiddenId) {
        console.log('setupAutocomplete called for:', inputId, dropdownId, hiddenId);
        const input = document.getElementById(inputId);
        const dropdown = document.getElementById(dropdownId);
        const hidden = document.getElementById(hiddenId);
        
        console.log('Location elements found:', { input: !!input, dropdown: !!dropdown, hidden: !!hidden });
        
        if (!input || !dropdown || !hidden) {
            console.error('Required elements not found for location autocomplete');
            return;
        }
            
        // Show dropdown on focus/click
        function showDropdown() {
            console.log('Showing dropdown for', inputId);
            const query = input.value.toLowerCase().trim();
            
            const filtered = locations.filter(loc => 
                !query || 
                loc.location.toLowerCase().includes(query) || 
                (loc.original_name && loc.original_name.toLowerCase().includes(query))
            );
            
            console.log('Showing', filtered.length, 'locations in dropdown');
            
            if (filtered.length > 0) {
                dropdown.innerHTML = filtered.map(loc => 
                    `<div class="autocomplete-item p-3 cursor-pointer hover:bg-teal-50 border-b border-gray-100 transition-colors" data-id="${loc.id}" data-name="${loc.location}">
                        <div class="font-medium text-gray-900">${loc.original_name || loc.location}</div>
                        <div class="text-sm text-gray-500">${String(loc.source) === 'location' ? 'Location' : 'Yard Location'}</div>
                    </div>`
                ).join('');
                dropdown.style.display = 'block';
                dropdown.style.position = 'absolute';
                dropdown.style.zIndex = '9999';
                dropdown.style.backgroundColor = 'white';
                dropdown.style.border = '1px solid #ccc';
                dropdown.style.borderRadius = '4px';
                dropdown.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
                dropdown.style.maxHeight = '200px';
                dropdown.style.overflowY = 'auto';
                dropdown.style.width = input.offsetWidth + 'px';
            }
        }
        
        input.addEventListener('focus', showDropdown);
        input.addEventListener('click', showDropdown);
        
        input.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            console.log('Input event triggered for', inputId, 'with query:', query);
            
            // Clear hidden input if search is empty
            if (!query || query.trim().length === 0) {
                hidden.value = '';
                dropdown.style.display = 'none';
                return;
            }
            
            const filtered = locations.filter(loc => 
                loc.location.toLowerCase().includes(query) || 
                (loc.original_name && loc.original_name.toLowerCase().includes(query))
            );
            
            console.log('Filtered results:', filtered.length, filtered.map(l => l.original_name + ' (' + l.source + ')'));
            
            if (filtered.length > 0) {
                dropdown.innerHTML = filtered.map(loc => 
                    `<div class="autocomplete-item p-3 cursor-pointer hover:bg-teal-50 border-b border-gray-100 transition-colors" data-id="${loc.id}" data-name="${loc.location}">
                        <div class="font-medium text-gray-900">${loc.original_name || loc.location}</div>
                        <div class="text-sm text-gray-500">${String(loc.source) === 'location' ? 'Location' : 'Yard Location'}</div>
                    </div>`
                ).join('');
                dropdown.style.display = 'block';
                dropdown.style.position = 'absolute';
                dropdown.style.zIndex = '9999';
                dropdown.style.backgroundColor = 'white';
                dropdown.style.border = '1px solid #ccc';
                dropdown.style.borderRadius = '4px';
                dropdown.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
                dropdown.style.maxHeight = '200px';
                dropdown.style.overflowY = 'auto';
                dropdown.style.width = input.offsetWidth + 'px';
            } else {
                dropdown.innerHTML = '<div class="p-3 text-gray-500 text-sm">No locations found</div>';
                dropdown.style.display = 'block';
            }
        });
        
        dropdown.addEventListener('click', function(e) {
            if (e.target.closest('.autocomplete-item')) {
                const item = e.target.closest('.autocomplete-item');
                const id = item.dataset.id;
                const name = item.dataset.name;
                
                input.value = name;
                hidden.value = id;
                dropdown.style.display = 'none';
                
                // Add visual feedback
                input.classList.add('border-green-500', 'bg-green-50');
                setTimeout(() => {
                    input.classList.remove('border-green-500', 'bg-green-50');
                }, 1000);
            }
        });
        
        // Hide dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }
    
    // Dynamic container details generation with individual type selection
    function generateContainerFields() {
        const noOfContainers = parseInt(document.getElementById('no_of_containers').value) || 0;
        const container = document.getElementById('container-details-container');
        const countDisplay = document.getElementById('container-count-display');
        
        // If already generated for the same count and real fields exist, skip
        const existingFields = container.querySelectorAll('.container-field');
        if (lastGeneratedContainerCount === noOfContainers && existingFields.length === noOfContainers) {
            return;
        }
        
        if (noOfContainers === 0) {
            container.innerHTML = '<div class="text-center text-gray-500 py-8">' +
                '<div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">' +
                    '<i class="fas fa-cube text-2xl text-gray-400"></i>' +
                '</div>' +
                '<p class="text-base font-medium mb-1">No containers selected</p>' +
                '<p class="text-sm">Select number of containers above to configure container details</p>' +
            '</div>';
            countDisplay.textContent = 'No containers selected';
            return;
        }
        
        countDisplay.textContent = noOfContainers + ' container' + (noOfContainers > 1 ? 's' : '') + ' selected';
        
        let containerFields = '';
        
        for (let i = 1; i <= noOfContainers; i++) {
            containerFields += '<div class="container-field bg-gray-50 rounded-lg p-4 border border-gray-200">' +
                '<div class="flex items-center gap-3 mb-4">' +
                    '<div class="w-8 h-8 bg-teal-600 text-white rounded-full flex items-center justify-center text-sm font-semibold">' +
                        i +
                    '</div>' +
                    '<div class="flex-1">' +
                        '<h4 class="font-medium text-gray-900">Container ' + i + '</h4>' +
                        '<p class="text-sm text-gray-500">Configure size and container number</p>' +
                    '</div>' +
                '</div>' +
                
                '<div class="grid grid-cols-1 md:grid-cols-3 gap-4">' +
                    '<div>' +
                        '<label class="block text-sm font-medium text-gray-700 mb-1">Container Size</label>' +
                        '<select name="container_types[]" id="container_type_' + i + '" class="input-enhanced" onchange="updateContainerNumbers(event, ' + i + ')">' +
                            '<option value="">Select size</option>' +
                            '<option value="20ft">20ft</option>' +
                            '<option value="40ft">40ft</option>' +
                        '</select>' +
                    '</div>' +
                    '<div id="container_numbers_' + i + '">' +
                        '<label class="block text-sm font-medium text-gray-700 mb-1">Container Number</label>' +
                        '<div class="text-sm text-gray-500">Select container size first</div>' +
                    '</div>' +
                '</div>' +
                
                // Per-container locations
                '<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">' +
                    '<div>' +
                        '<label class="block text-sm font-medium text-gray-700 mb-1">From Location (Optional)</label>' +
                        '<div class="relative autocomplete-container">' +
                            '<input type="text" id="container_from_location_' + i + '" class="input-enhanced pr-10 container-from-input" placeholder="Type to search locations..." autocomplete="off" />' +
                            '<div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">' +
                                '<i class="fas fa-search text-gray-400"></i>' +
                            '</div>' +
                            '<input type="hidden" name="container_from_location_ids[]" id="container_from_location_id_' + i + '" />' +
                            '<div class="autocomplete-dropdown" id="container_from_location_dropdown_' + i + '"></div>' +
                        '</div>' +
                        '<div class="mt-1 text-xs text-gray-500">' +
                            '<i class="fas fa-info-circle mr-1"></i>' +
                            'Search locations and yard locations' +
                        '</div>' +
                    '</div>' +
                    '<div>' +
                        '<label class="block text-sm font-medium text-gray-700 mb-1">To Location (Optional)</label>' +
                        '<div class="relative autocomplete-container">' +
                            '<input type="text" id="container_to_location_' + i + '" class="input-enhanced pr-10 container-to-input" placeholder="Type to search locations..." autocomplete="off" />' +
                            '<div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">' +
                                '<i class="fas fa-search text-gray-400"></i>' +
                            '</div>' +
                            '<input type="hidden" name="container_to_location_ids[]" id="container_to_location_id_' + i + '" />' +
                            '<div class="autocomplete-dropdown" id="container_to_location_dropdown_' + i + '"></div>' +
                        '</div>' +
                        '<div class="mt-1 text-xs text-gray-500">' +
                            '<i class="fas fa-info-circle mr-1"></i>' +
                            'Search locations and yard locations' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                
                '<div class="mt-3 flex justify-end">' +
                    '<button type="button" class="text-sm text-gray-500 hover:text-red-500 hover:bg-red-50 px-2 py-1 rounded transition-colors" ' +
                            'onclick="clearContainerField(' + i + ')" title="Clear container ' + i + '">' +
                        '<i class="fas fa-times mr-1"></i>Clear' +
                    '</button>' +
                '</div>' +
            '</div>';
        }
        
        container.innerHTML = containerFields;
        lastGeneratedContainerCount = noOfContainers;
        
        // Initialize per-container autocompletes
        for (let j = 1; j <= noOfContainers; j++) {
            setupAutocomplete('container_from_location_' + j, 'container_from_location_dropdown_' + j, 'container_from_location_id_' + j);
            setupAutocomplete('container_to_location_' + j, 'container_to_location_dropdown_' + j, 'container_to_location_id_' + j);
        }
        
        // Add animation to new fields
        const newFields = container.querySelectorAll('.container-field');
        newFields.forEach((field, index) => {
            field.style.opacity = '0';
            field.style.transform = 'translateY(20px)';
            setTimeout(() => {
                field.style.transition = 'all 0.3s ease';
                field.style.opacity = '1';
                field.style.transform = 'translateY(0)';
            }, index * 100);
        });
    }
    
    // Update container numbers based on selected type
    function updateContainerNumbers(e, containerIndex) {
        if (e && typeof e.preventDefault === 'function') {
            e.preventDefault();
            if (typeof e.stopPropagation === 'function') e.stopPropagation();
        }
        const containerType = document.getElementById('container_type_' + containerIndex).value;
        const numbersContainer = document.getElementById('container_numbers_' + containerIndex);
        
        if (containerType === '20ft' || containerType === '40ft') {
            numbersContainer.innerHTML = '<label class="block text-sm font-medium text-gray-700 mb-1">Container Number *</label>' +
                '<input type="text" name="container_numbers[]" placeholder="Container number" ' +
                       'class="input-enhanced" maxlength="20" required>';
        } else {
            numbersContainer.innerHTML = '<label class="block text-sm font-medium text-gray-700 mb-1">Container Number</label>' +
                '<div class="text-sm text-gray-500">Select container size first</div>';
        }
    }
    
    // Clear individual container field
    function clearContainerField(containerNumber) {
        // Clear container type
        const typeSelect = document.getElementById('container_type_' + containerNumber);
        if (typeSelect) {
            typeSelect.value = '';
        }
        
        // Reset container numbers section
        const numbersContainer = document.getElementById('container_numbers_' + containerNumber);
        if (numbersContainer) {
            numbersContainer.innerHTML = '<label class="block text-sm font-medium text-gray-700 mb-1">Container Number</label>' +
                '<div class="text-sm text-gray-500">Select container size first</div>';
        }
    }
    
    // Apply global From/To locations to all per-container fields when enabled
    function applyGlobalLocationsToContainers() {
        const sameAll = document.getElementById('same_locations_all');
        if (!sameAll || !sameAll.checked) return;
        const fromName = document.getElementById('from_location') ? document.getElementById('from_location').value || '' : '';
        const fromId = document.getElementById('from_location_id') ? document.getElementById('from_location_id').value || '' : '';
        const toName = document.getElementById('to_location') ? document.getElementById('to_location').value || '' : '';
        const toId = document.getElementById('to_location_id') ? document.getElementById('to_location_id').value || '' : '';
        const count = parseInt(document.getElementById('no_of_containers') ? document.getElementById('no_of_containers').value : '0') || 0;
        for (let i = 1; i <= count; i++) {
            const fromInput = document.getElementById('container_from_location_' + i);
            const fromHidden = document.getElementById('container_from_location_id_' + i);
            const toInput = document.getElementById('container_to_location_' + i);
            const toHidden = document.getElementById('container_to_location_id_' + i);
            if (fromInput && fromHidden) { fromInput.value = fromName; fromHidden.value = fromId; }
            if (toInput && toHidden) { toInput.value = toName; toHidden.value = toId; }
        }
    }

    // PDF Upload Handler
    function setupPDFUpload() {
        const fileInput = document.getElementById('booking_receipt_pdf');
        const uploadArea = fileInput ? fileInput.closest('div').querySelector('label') : null;
        
        console.log('PDF Upload Setup - fileInput:', fileInput);
        console.log('PDF Upload Setup - uploadArea:', uploadArea);
        
        if (fileInput && uploadArea) {
            // Handle file selection
            fileInput.addEventListener('change', function(e) {
                console.log('PDF Upload - File change event triggered');
                console.log('PDF Upload - Files:', e.target.files);
                
                const file = e.target.files[0];
                if (file) {
                    console.log('PDF Upload - File selected:', file.name, 'Size:', file.size, 'Type:', file.type);
                    
                    // Validate file type
                    if (file.type !== 'application/pdf') {
                        console.log('PDF Upload - Invalid file type:', file.type);
                        alert('Please select a PDF file only.');
                        fileInput.value = '';
                        return;
                    }
                    
                    // Validate file size (10MB)
                    if (file.size > 10 * 1024 * 1024) {
                        console.log('PDF Upload - File too large:', file.size);
                        alert('File size must be less than 10MB.');
                        fileInput.value = '';
                        return;
                    }
                    
                    console.log('PDF Upload - File validation passed');
                    
                    // Update upload area to show selected file
                    uploadArea.innerHTML = `
                        <div class="flex flex-col items-center justify-center pt-5 pb-6">
                            <i class="fas fa-file-pdf text-3xl text-red-500 mb-2"></i>
                            <p class="mb-2 text-sm text-gray-700 font-semibold">${file.name}</p>
                            <p class="text-xs text-gray-500">Click to change file</p>
                        </div>
                    `;
                } else {
                    console.log('PDF Upload - No file selected');
                }
            });
            
            // Handle click to open file dialog
            uploadArea.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('PDF Upload - Upload area clicked');
                console.log('PDF Upload - Triggering file input click');
                fileInput.click();
            });
            
            // Handle drag and drop
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                uploadArea.classList.add('bg-blue-50', 'border-blue-400');
            });
            
            uploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                uploadArea.classList.remove('bg-blue-50', 'border-blue-400');
            });
            
            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                uploadArea.classList.remove('bg-blue-50', 'border-blue-400');
                
                const files = e.dataTransfer.files;
                console.log('PDF Upload - Files dropped:', files.length);
                if (files.length > 0) {
                    fileInput.files = files;
                    fileInput.dispatchEvent(new Event('change'));
                }
            });
        }
    }

    // Initialize everything when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        
        // Initialize autocomplete for client field
        console.log('Setting up client autocomplete...');
        setupClientAutocomplete();
    
        // Initialize autocomplete for both location fields
        console.log('Setting up location autocomplete...');
        setupAutocomplete('from_location', 'from_location_dropdown', 'from_location_id');
        setupAutocomplete('to_location', 'to_location_dropdown', 'to_location_id');
        
        // Initialize PDF upload
        console.log('Setting up PDF upload...');
        setupPDFUpload();
    
        // Generate booking ID functionality
        const generateBtn = document.getElementById('generate-booking-id-btn');
        if (generateBtn) {
            generateBtn.addEventListener('click', function() {
                console.log('Generate button clicked');
                const button = this;
                const bookingIdInput = document.getElementById('booking_id');
                
                if (!bookingIdInput) {
                    console.error('Booking ID input not found');
                    return;
                }
                
                // Prevent regenerating if already present
                if (bookingIdInput.value && bookingIdInput.value.trim().length > 0) {
                    alert('Booking ID already set. Clear it first if you want to regenerate.');
                    return;
                }
                
                // Add loading state to button
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span class="hidden sm:inline">Generating...</span>';
                
                // Fetch new booking ID
                console.log('Making request to: ?action=generate_booking_id');
                fetch('?action=generate_booking_id')
                    .then(response => {
                        console.log('Response received:', response.status, response.statusText);
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('Data received:', data);
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        if (!data.booking_id) {
                            throw new Error('No booking ID returned from server');
                        }
                        
                        bookingIdInput.value = data.booking_id;
                        bookingIdInput.classList.add('booking-id-success');
                        
                        // Preview approval requirements for generated ID
                        previewApprovalRequirements(data.booking_id);
                        
                        // Remove success styling after 2 seconds
                        setTimeout(() => {
                            bookingIdInput.classList.remove('booking-id-success');
                        }, 2000);
                    })
                    .catch(error => {
                        console.error('Error generating booking ID:', error);
                        alert('Error generating booking ID: ' + error.message);
                    })
                    .finally(() => {
                        button.disabled = false;
                        button.innerHTML = '<i class="fas fa-magic"></i> <span class="hidden sm:inline">Generate</span>';
                    });
            });
        }
        
        // Add event listeners for dynamic field generation
        document.getElementById('no_of_containers').addEventListener('input', function(){
            generateContainerFields();
            // Re-apply global locations if enabled after regenerating fields
            applyGlobalLocationsToContainers();
        });
        document.getElementById('no_of_containers').addEventListener('change', function(){
            generateContainerFields();
            applyGlobalLocationsToContainers();
        });
        
        // Preview approval requirements for booking ID
        document.getElementById('booking_id').addEventListener('input', function() {
            const bookingId = this.value;
            previewApprovalRequirements(bookingId);
        });

        // Same for all checkbox behavior: apply immediately and on global changes
        const sameAll = document.getElementById('same_locations_all');
        if (sameAll) {
            sameAll.addEventListener('change', applyGlobalLocationsToContainers);
        }
        const fromInput = document.getElementById('from_location');
        const toInput = document.getElementById('to_location');
        if (fromInput) fromInput.addEventListener('input', function(){ applyGlobalLocationsToContainers(); });
        if (toInput) toInput.addEventListener('input', function(){ applyGlobalLocationsToContainers(); });
        
        // Form validation and submission
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                console.log('Form submit event triggered');
                const clientId = document.getElementById('client_id').value;
                const noOfContainers = document.getElementById('no_of_containers').value;
                const bookingId = document.getElementById('booking_id').value.trim();
                
                console.log('Form values:', { clientId, noOfContainers, bookingId });
                
                if (!clientId || !noOfContainers || !bookingId) {
                    console.log('Form validation failed');
                    e.preventDefault();
                    showNotification('Please fill in all required fields including Booking ID.', 'error');
                    return false;
                }
                
                console.log('Form validation passed, submitting...');
            });
        }
    });

    // Show notification function
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm transform transition-all duration-300 ${
            type === 'error' ? 'bg-red-100 border border-red-300 text-red-800' : 
            type === 'success' ? 'bg-green-100 border border-green-300 text-green-800' :
            'bg-blue-100 border border-blue-300 text-blue-800'
        }`;
        
        notification.innerHTML = `
            <div class="flex items-center">
                <i class="fas ${type === 'error' ? 'fa-exclamation-circle' : type === 'success' ? 'fa-check-circle' : 'fa-info-circle'} mr-2"></i>
                <span>${message}</span>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.style.transform = 'translateX(100%)';
            setTimeout(() => {
                document.body.removeChild(notification);
            }, 300);
        }, 5000);
    }

    // Location Modal Functions
    function openLocationModal(suggestedName = '') {
        // Create modal HTML if it doesn't exist
        if (!document.getElementById('locationModal')) {
            const modalHTML = `
                <div id="locationModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
                    <div class="flex items-center justify-center min-h-screen p-4">
                        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full transform transition-all duration-300">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-6">
                                    <h3 class="text-xl font-bold text-gray-800">Add New Yard Location</h3>
                                    <button onclick="closeLocationModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                                        <i class="fas fa-times text-xl"></i>
                                    </button>
                                </div>
                                
                                <form id="locationForm" class="space-y-4">
                                    <div>
                                        <label for="newLocationName" class="block text-sm font-medium text-gray-700 mb-2">
                                            <i class="fas fa-map-marker-alt text-teal-600 mr-2"></i>Yard Location Name *
                                        </label>
                                        <input type="text" id="newLocationName" class="input-enhanced" placeholder="Enter yard location name" required>
                                        <p class="text-xs text-gray-500 mt-1">This will be added to yard locations</p>
                                    </div>
                                </form>
                                
                                <div class="flex gap-3 mt-6">
                                    <button id="saveLocationBtn" onclick="saveNewYardLocation()" 
                                            class="flex-1 bg-teal-600 hover:bg-teal-700 text-white font-semibold py-3 px-4 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-save mr-2"></i>Save Yard Location
                                    </button>
                                    <button onclick="closeLocationModal()" 
                                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white font-semibold py-3 px-4 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }
        
        const modal = document.getElementById('locationModal');
        const input = document.getElementById('newLocationName');
        
        if (input) {
            input.value = suggestedName || '';
        }
        
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        
        // Focus on input
        setTimeout(() => {
            if (input) input.focus();
        }, 100);
    }

    function closeLocationModal() {
        const modal = document.getElementById('locationModal');
        if (modal) {
            modal.classList.add('hidden');
        }
        document.body.style.overflow = 'auto';
        
        // Clear form
        const input = document.getElementById('newLocationName');
        if (input) {
            input.value = '';
        }
    }

    function saveNewYardLocation() {
        const input = document.getElementById('newLocationName');
        const name = input ? input.value.trim() : '';
        
        if (!name) {
            alert('Please enter yard location name');
            return;
        }
        
        // Show loading state
        const saveBtn = document.getElementById('saveLocationBtn');
        const originalText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
        saveBtn.disabled = true;
        
        // Create form data
        const formData = new FormData();
        formData.append('action', 'add_yard_location');
        formData.append('location_name', name);
        
        // Send AJAX request
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Add to locations array as yard location
                locations.push({
                    id: 'yard_' + data.location_id,
                    location: name,
                    source: 'yard'
                });
                
                alert('Yard location added successfully!');
                closeLocationModal();
                
                // Refresh location dropdowns
                setupAutocomplete('from_location', 'from_location_dropdown', 'from_location_id');
                setupAutocomplete('to_location', 'to_location_dropdown', 'to_location_id');
            } else {
                alert('Error: ' + (data.message || 'Unable to add yard location'));
            }
        })
        .catch(error => {
            console.error('Error saving yard location:', error);
            alert('Error saving yard location. Please try again.');
        })
        .finally(() => {
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        });
    }
</script>
</body>
</html>