<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Check if user has L2 supervisor or superadmin access
$user_role = $_SESSION['role'];
$allowed_roles = ['l2_supervisor', 'superadmin'];
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. This page is only for L2 supervisors and superadmins.";
    header("Location: dashboard.php");
    exit();
}

$success_message = '';
$error_message = '';
// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submission with CSRF validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_container_details') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    // Check if this submission has already been processed
    $booking_id = $_POST['booking_id'];
    $submission_hash = md5(json_encode($_POST['containers']) . $_SESSION['csrf_token']);
    if (isset($_SESSION['processed_submissions'][$booking_id]) && in_array($submission_hash, $_SESSION['processed_submissions'][$booking_id])) {
        echo json_encode(['success' => true, 'message' => 'Submission already processed']);
        exit;
    }

    try {
    handleContainerImageUpload($pdo, $_POST, $_FILES);

    // Store submission hash to prevent duplicates
    $_SESSION['processed_submissions'][$booking_id][] = $submission_hash;
    echo json_encode(['success' => true, 'message' => 'Container details updated']);
    } catch (Exception $e) {
        error_log("Container update error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to update container details: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['ajax']) {
        case 'search_locations':
            searchLocations($pdo, $_GET['query'] ?? '');
            break;
        case 'search_vehicles':
            searchVehicles($pdo, $_GET['query'] ?? '');
            break;
        case 'search_vendors':
            searchVendors($pdo, $_GET['query'] ?? '');
            break;
        case 'search_l1_supervisors':
            searchL1Supervisors($pdo, $_GET['query'] ?? '');
            break;
        case 'acknowledge_booking':
            acknowledgeBooking($pdo, $_POST['booking_id'] ?? 0);
            break;
        case 'get_booking_containers':
            getBookingContainers($pdo, $_GET['booking_id'] ?? 0);
            break;
        case 'search_vendor_vehicles':
            searchVendorVehicles($pdo, $_GET['query'] ?? '', $_GET['vendor_id'] ?? 0);
            break;
        case 'search_owned_vehicles':
            searchOwnedVehicles($pdo, $_GET['query'] ?? '');
            break;
        default:
            echo json_encode(['error' => 'Invalid request']);
    }
    exit();
}

// AJAX Functions
function getBookingContainers($pdo, $booking_id) {
    $stmt = $pdo->prepare("SELECT no_of_containers as total_containers FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($booking) {
        echo json_encode(['success' => true, 'total_containers' => $booking['total_containers']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
    }
}

function searchL1Supervisors($pdo, $query) {
    $query = '%' . trim($query) . '%';
    $stmt = $pdo->prepare("
        SELECT id, full_name, phone_number, email
        FROM users 
        WHERE (full_name LIKE ? OR phone_number LIKE ?) 
        AND role = 'l1_supervisor'
        ORDER BY full_name LIMIT 10
    ");
    $stmt->execute([$query, $query]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function searchLocations($pdo, $query) {
    $query = '%' . trim($query) . '%';
    
    try {
        // Search regular locations
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                location as name, 
                'location' as type,
                CONCAT('LOC-', id) as unique_id
            FROM location 
            WHERE location LIKE ? 
            ORDER BY location 
            LIMIT 10
        ");
        $stmt->execute([$query]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Search yard locations
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                yard_name as name, 
                'yard' as type,
                CONCAT('YARD-', id) as unique_id
            FROM yard_locations 
            WHERE yard_name LIKE ? 
            AND is_active = 1 
            ORDER BY yard_name 
            LIMIT 10
        ");
        $stmt->execute([$query]);
        $yards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Combine and return results with type information
        $all_locations = array_merge($locations, $yards);
        
        // Add display formatting
        foreach ($all_locations as &$location) {
            $location['display_name'] = $location['name'] . ' (' . strtoupper($location['type']) . ')';
            $location['value'] = $location['type'] . '|' . $location['id']; // Format: "location|123" or "yard|456"
        }
        
        echo json_encode($all_locations);
        
    } catch (Exception $e) {
        error_log("Error in searchLocations: " . $e->getMessage());
        echo json_encode([]);
    }
}
function searchVehicles($pdo, $query) {
    $query = '%' . trim($query) . '%';
    
    try {
        // Search owned vehicles - using correct column names from your schema
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                vehicle_number, 
                COALESCE(make_model, '') as make, 
                '' as model,  
                COALESCE(driver_name, 'No Driver') as driver_name,
                'owned' as vehicle_type,
                COALESCE(current_status, 'unknown') as status
            FROM vehicles 
            WHERE (
                vehicle_number LIKE ? 
                OR COALESCE(make_model, '') LIKE ? 
                OR COALESCE(driver_name, '') LIKE ?
                OR COALESCE(owner_name, '') LIKE ?
            )
            AND (
                current_status IN ('available', 'on_trip', 'maintenance', 'inactive') 
                OR current_status IS NULL
            )
            AND (
                approval_status = 'approved' 
                OR approval_status IS NULL
            )
            AND (deleted_at IS NULL)  -- Exclude soft-deleted records
            ORDER BY vehicle_number 
            LIMIT 10
        ");
        $stmt->execute([$query, $query, $query, $query]);
        $owned_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Search vendor vehicles - using correct column names from your schema
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                vehicle_number, 
                COALESCE(make, '') as make, 
                COALESCE(model, '') as model,  
                COALESCE(driver_name, 'No Driver') as driver_name,
                'vendor' as vehicle_type,
                COALESCE(status, 'unknown') as status
            FROM vendor_vehicles 
            WHERE (
                vehicle_number LIKE ? 
                OR COALESCE(make, '') LIKE ? 
                OR COALESCE(model, '') LIKE ?
                OR COALESCE(driver_name, '') LIKE ?
            )
            AND (
                status IN ('active', 'inactive', 'maintenance') 
                OR status IS NULL
            )
            AND (deleted_at IS NULL)  -- Exclude soft-deleted records
            ORDER BY vehicle_number 
            LIMIT 10
        ");
        $stmt->execute([$query, $query, $query, $query]);
        $vendor_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Combine results
        $all_vehicles = array_merge($owned_vehicles, $vendor_vehicles);
        
        // Debug logging
        error_log("Vehicle search query: " . trim($query, '%'));
        error_log("Owned vehicles found: " . count($owned_vehicles));
        error_log("Vendor vehicles found: " . count($vendor_vehicles));
        error_log("Total vehicles found: " . count($all_vehicles));
        
        // If no results, try a simpler search without status filters
        if (empty($all_vehicles)) {
            error_log("No vehicles found with status filters, trying without filters...");
            
            // Retry without status filters
            $stmt = $pdo->prepare("
                SELECT 
                    id, 
                    vehicle_number, 
                    COALESCE(make_model, '') as make,
                    '' as model,
                    COALESCE(driver_name, 'No Driver') as driver_name,
                    'owned' as vehicle_type,
                    COALESCE(current_status, 'unknown') as status
                FROM vehicles 
                WHERE vehicle_number LIKE ? 
                AND (deleted_at IS NULL)
                ORDER BY vehicle_number 
                LIMIT 5
            ");
            $stmt->execute([$query]);
            $owned_simple = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("
                SELECT 
                    id, 
                    vehicle_number, 
                    COALESCE(make, '') as make,
                    COALESCE(model, '') as model,
                    COALESCE(driver_name, 'No Driver') as driver_name,
                    'vendor' as vehicle_type,
                    COALESCE(status, 'unknown') as status
                FROM vendor_vehicles 
                WHERE vehicle_number LIKE ? 
                AND (deleted_at IS NULL)
                ORDER BY vehicle_number 
                LIMIT 5
            ");
            $stmt->execute([$query]);
            $vendor_simple = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $all_vehicles = array_merge($owned_simple, $vendor_simple);
            error_log("Simplified search found: " . count($all_vehicles) . " vehicles");
        }
        
        echo json_encode($all_vehicles);
        
    } catch (PDOException $e) {
        error_log("Database error in searchVehicles: " . $e->getMessage());
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("General error in searchVehicles: " . $e->getMessage());
        echo json_encode(['error' => 'Search error: ' . $e->getMessage()]);
    }
}

function searchVendors($pdo, $query) {
    $query = '%' . trim($query) . '%';
    $stmt = $pdo->prepare("
        SELECT id, vendor_code, company_name, contact_person, mobile, email
        FROM vendors 
        WHERE (company_name LIKE ? OR vendor_code LIKE ? OR contact_person LIKE ?) 
        AND status = 'active'
        ORDER BY company_name LIMIT 10
    ");
    $stmt->execute([$query, $query, $query]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function searchVendorVehicles($pdo, $query, $vendor_id) {
    $query = '%' . trim($query) . '%';
    $stmt = $pdo->prepare("
        SELECT id, vehicle_number, make, model, driver_name, status
        FROM vendor_vehicles 
        WHERE vendor_id = ? 
        AND (vehicle_number LIKE ? OR make LIKE ? OR model LIKE ? OR driver_name LIKE ?)
        AND (deleted_at IS NULL)
        ORDER BY vehicle_number LIMIT 10
    ");
    $stmt->execute([$vendor_id, $query, $query, $query, $query]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function searchOwnedVehicles($pdo, $query) {
    $query = '%' . trim($query) . '%';
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                vehicle_number, 
                COALESCE(make_model, '') as make, 
                '' as model,  
                COALESCE(driver_name, 'No Driver') as driver_name,
                'owned' as vehicle_type,
                COALESCE(current_status, 'unknown') as status
            FROM vehicles 
            WHERE (
                vehicle_number LIKE ? 
                OR COALESCE(make_model, '') LIKE ? 
                OR COALESCE(driver_name, '') LIKE ?
                OR COALESCE(owner_name, '') LIKE ?
            )
            AND (
                current_status IN ('available', 'on_trip', 'maintenance', 'inactive') 
                OR current_status IS NULL
            )
            AND (
                approval_status = 'approved' 
                OR approval_status IS NULL
            )
            AND (deleted_at IS NULL)
            ORDER BY vehicle_number 
            LIMIT 10
        ");
        $stmt->execute([$query, $query, $query, $query]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        
    } catch (PDOException $e) {
        error_log("Database error in searchOwnedVehicles: " . $e->getMessage());
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("General error in searchOwnedVehicles: " . $e->getMessage());
        echo json_encode(['error' => 'Search error: ' . $e->getMessage()]);
    }
}

// New function to search unlinked vehicles (not tied to any vendor)
function searchUnlinkedVehicles($pdo, $query) {
    $query = '%' . trim($query) . '%';
    
    try {
        // Search for vehicles that have been used in the past but aren't vendor-specific
        // These could be from a general vehicle pool or previously hired vehicles
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                vehicle_number,
                COALESCE(make_model, CONCAT(COALESCE(make, ''), ' ', COALESCE(model, ''))) as vehicle_description,
                'unlinked' as vehicle_type
            FROM (
                -- Get vehicles from past trips that might be available for hire
                SELECT 
                    reference_number as vehicle_number,
                    CONCAT('Trip Vehicle - ', reference_number) as make_model
                FROM trips 
                WHERE reference_number LIKE ?
                
                UNION
                
                -- Get any other vehicle records that match the search
                SELECT 
                    vehicle_number,
                    make_model
                FROM vehicles 
                WHERE vehicle_number LIKE ? 
                AND deleted_at IS NULL
                
                UNION
                
                -- Get vendor vehicles that could be referenced
                SELECT 
                    vehicle_number,
                    CONCAT(COALESCE(make, ''), ' ', COALESCE(model, '')) as make_model
                FROM vendor_vehicles 
                WHERE vehicle_number LIKE ? 
                AND deleted_at IS NULL
            ) as all_vehicles
            ORDER BY vehicle_number 
            LIMIT 10
        ");
        $stmt->execute([$query, $query, $query]);
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($vehicles);
        
    } catch (Exception $e) {
        error_log("Error in searchUnlinkedVehicles: " . $e->getMessage());
        echo json_encode([]);
    }
}


function acknowledgeBooking($pdo, $booking_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE bookings 
            SET status = 'being_addressed',
                acknowledged_by = ?,
                acknowledged_at = NOW(),
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $result = $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $booking_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log activity
            $stmt = $pdo->prepare("
                INSERT INTO booking_activities (booking_id, activity_type, description, created_by, created_at)
                VALUES (?, 'booking_acknowledged', ?, ?, NOW())
            ");
            $description = "Booking acknowledged by " . $_SESSION['full_name'] . " - Started processing";
            $stmt->execute([$booking_id, $description, $_SESSION['user_id']]);
            
            echo json_encode(['success' => true, 'message' => 'Booking acknowledged successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Booking not found or already processed']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_GET['ajax'])) {
    try {
        $action = $_POST['action'] ?? '';
        
        $pdo->beginTransaction();
        
        switch ($action) {
            case 'contact_l1':
                handleL1Contact($pdo, $_POST);
                break;
            case 'upload_container_images':
                handleContainerImageUpload($pdo, $_POST, $_FILES);
                break;
            case 'assign_vehicles':
                handleVehicleAssignment($pdo, $_POST);
                break;
            default:
                throw new Exception("Invalid action");
        }
        
        $pdo->commit();
        $success_message = "Action completed successfully!";
        
    } catch (Exception $e) {
        $pdo->rollback();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Function to handle L1 supervisor contact
function handleL1Contact($pdo, $data) {
    $booking_id = $data['booking_id'];
    $l1_contact = $data['l1_contact'];
    $message = $data['whatsapp_message'];
    
    // Update booking status
    $stmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'awaiting_containers',
            l1_contacted_at = NOW(),
            l1_contact_number = ?,
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$l1_contact, $_SESSION['user_id'], $booking_id]);
    
    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO booking_activities (booking_id, activity_type, description, created_by, created_at)
        VALUES (?, 'l1_contacted', ?, ?, NOW())
    ");
    $description = "L1 Supervisor contacted at " . $l1_contact . " for container details";
    $stmt->execute([$booking_id, $description, $_SESSION['user_id']]);
    
    // Here you would integrate with WhatsApp API
    error_log("WhatsApp message to {$l1_contact}: {$message}");
}

// Function to handle container image upload
function debugContainerStatus($pdo, $booking_id) {
    error_log("=== DEBUG: Container Status for Booking ID: $booking_id ===");
    
    // Check total containers expected
    $stmt = $pdo->prepare("SELECT no_of_containers FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $total_expected = $stmt->fetchColumn();
    error_log("Total containers expected: " . ($total_expected ?? 'NULL'));
    
    // Check containers in database
    $stmt = $pdo->prepare("
        SELECT 
            id,
            container_sequence,
            container_number_1,
            container_type,
            from_location_id,
            to_location_id,
            CASE WHEN container_number_1 IS NOT NULL AND container_number_1 != '' THEN 1 ELSE 0 END as is_filled
        FROM booking_containers 
        WHERE booking_id = ?
        ORDER BY container_sequence
    ");
    $stmt->execute([$booking_id]);
    $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Containers found in database: " . count($containers));
    foreach ($containers as $container) {
        error_log("Container {$container['container_sequence']}: number={$container['container_number_1']}, type={$container['container_type']}, filled={$container['is_filled']}");
    }
    
    // Count filled containers
    $filled_count = array_sum(array_column($containers, 'is_filled'));
    error_log("Filled containers count: $filled_count");
    
    // Check current booking status
    $stmt = $pdo->prepare("SELECT status FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $current_status = $stmt->fetchColumn();
    error_log("Current booking status: $current_status");
    
    error_log("=== END DEBUG ===");
    
    return [
        'total_expected' => $total_expected,
        'total_in_db' => count($containers),
        'filled_count' => $filled_count,
        'current_status' => $current_status,
        'should_be_ready' => ($filled_count >= $total_expected && $total_expected > 0)
    ];
}

// Fixed handleContainerImageUpload function with better status logic
function handleContainerImageUpload($pdo, $data, $files) {
    try {
    $booking_id = $data['booking_id'];
    $container_updates = $data['containers'] ?? [];
    $total_containers = $data['total_containers'] ?? 0;
        
        // Debug logging
        error_log("Container update debug - Booking ID: $booking_id, Total containers: $total_containers");
        error_log("Container updates: " . json_encode($container_updates));
    
    $upload_dir = 'uploads/container_images/' . $booking_id . '/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $processed_count = 0;
    
    foreach ($container_updates as $key => $container_data) {
        // Skip if no meaningful data provided
        if (empty($container_data['number1']) && empty($container_data['id']) && 
            empty($container_data['container_type'])) {
            continue;
        }
        
        // Parse location data with type information
        $from_location_data = parseLocationData($container_data['from_location'] ?? '');
        $to_location_data = parseLocationData($container_data['to_location'] ?? '');
        
        // Debug location parsing
        error_log("Container $key - From location: " . json_encode($from_location_data));
        error_log("Container $key - To location: " . json_encode($to_location_data));
        
        $image1_path = null;
        $image2_path = null;
        
        if (isset($files['container_images'])) {
            foreach ($files['container_images']['tmp_name'] as $file_key => $tmp_name) {
                if (strpos($file_key, "container_{$key}_image1") !== false && $tmp_name) {
                    $image1_path = uploadImage($tmp_name, $upload_dir, "container_{$key}_image1");
                }
                if (strpos($file_key, "container_{$key}_image2") !== false && $tmp_name) {
                    $image2_path = uploadImage($tmp_name, $upload_dir, "container_{$key}_image2");
                }
            }
        }
        
        if (!empty($container_data['id'])) {
            // Update existing container
            try {
                // Handle empty strings in PHP to avoid collation issues
                $number1 = !empty($container_data['number1']) ? $container_data['number1'] : null;
                $number2 = !empty($container_data['number2']) ? $container_data['number2'] : null;
                $container_type = !empty($container_data['container_type']) ? $container_data['container_type'] : null;
                
            $stmt = $pdo->prepare("
                UPDATE booking_containers 
                    SET container_number_1 = COALESCE(?, container_number_1),
                        container_number_2 = COALESCE(?, container_number_2),
                        container_type = COALESCE(?, container_type),
                    from_location_id = COALESCE(?, from_location_id),
                    from_location_type = COALESCE(?, from_location_type),
                    to_location_id = COALESCE(?, to_location_id),
                    to_location_type = COALESCE(?, to_location_type),
                    container_image_1 = COALESCE(?, container_image_1),
                    container_image_2 = COALESCE(?, container_image_2),
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                    $number1,
                    $number2,
                    $container_type,
                $from_location_data['id'],
                $from_location_data['type'],
                $to_location_data['id'],
                $to_location_data['type'],
                $image1_path,
                $image2_path,
                $_SESSION['user_id'],
                $container_data['id']
            ]);
                error_log("Successfully updated container ID: " . $container_data['id']);
            } catch (Exception $e) {
                error_log("Error updating container " . $container_data['id'] . ": " . $e->getMessage());
                throw $e;
            }
        } else {
            // Insert new container only if we have essential data
            if (!empty($container_data['number1']) || !empty($container_data['container_type'])) {
                try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as seq FROM booking_containers WHERE booking_id = ?
                ");
                $stmt->execute([$booking_id]);
                $seq = $stmt->fetchColumn() + 1;
                    
                    // Handle empty strings in PHP to avoid collation issues
                    $new_number1 = !empty($container_data['number1']) ? $container_data['number1'] : null;
                    $new_number2 = !empty($container_data['number2']) ? $container_data['number2'] : null;
                    $new_container_type = !empty($container_data['container_type']) ? $container_data['container_type'] : null;
                
                $stmt = $pdo->prepare("
                    INSERT INTO booking_containers (
                        booking_id, container_sequence, container_type,
                        container_number_1, container_number_2,
                        from_location_id, from_location_type,
                        to_location_id, to_location_type,
                        container_image_1, container_image_2,
                        created_by, created_at, updated_by, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())
                ");
                $stmt->execute([
                    $booking_id,
                    $seq,
                        $new_container_type,
                        $new_number1,
                        $new_number2,
                    $from_location_data['id'],
                    $from_location_data['type'],
                    $to_location_data['id'],
                    $to_location_data['type'],
                    $image1_path,
                    $image2_path,
                    $_SESSION['user_id'],
                    $_SESSION['user_id']
                ]);
                    $processed_count++;
                    error_log("Successfully inserted new container for booking $booking_id");
                } catch (Exception $e) {
                    error_log("Error inserting new container: " . $e->getMessage());
                    throw $e;
                }
            }
        }
    }
    
    } catch (Exception $e) {
        error_log("Fatal error in handleContainerImageUpload: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        throw new Exception("Container update failed: " . $e->getMessage());
    }
    
    if ($processed_count > 0) {
        // Debug current state
        $debug_info = debugContainerStatus($pdo, $booking_id);
        
        // FIXED: Better logic for determining if containers are ready
        // Count containers that have meaningful data (number OR type filled)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM booking_containers 
            WHERE booking_id = ? 
            AND (
                (container_number_1 IS NOT NULL AND container_number_1 != '') 
                OR 
                (container_type IS NOT NULL AND container_type != '')
            )
        ");
        $stmt->execute([$booking_id]);
        $filled_count = $stmt->fetchColumn();
        
        // Also check total containers that exist (even if partially filled)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM booking_containers WHERE booking_id = ?");
        $stmt->execute([$booking_id]);
        $total_existing = $stmt->fetchColumn();
        
        error_log("After update - Filled: $filled_count, Total existing: $total_existing, Expected: $total_containers");
        
        // Determine new status based on completion
        $new_status = 'awaiting_containers'; // Default
        
        if ($filled_count >= $total_containers && $total_containers > 0) {
            // All required containers have been filled with details
            $new_status = 'containers_updated';
        } elseif ($filled_count > 0) {
            // Some containers filled, but not all
            $new_status = 'awaiting_containers';
        }
        
        error_log("Setting new status to: $new_status");
        
        $stmt = $pdo->prepare("
            UPDATE bookings 
            SET status = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$new_status, $_SESSION['user_id'], $booking_id]);
        
        $stmt = $pdo->prepare("
            INSERT INTO booking_activities (booking_id, activity_type, description, created_by, created_at)
            VALUES (?, 'container_updated', ?, ?, NOW())
        ");
        $description = "Container update: $filled_count of $total_containers containers completed. Status: $new_status";
        $stmt->execute([$booking_id, $description, $_SESSION['user_id']]);
    }
}

// Helper function to parse location data format: "type|id"
function parseLocationData($location_value) {
    if (empty($location_value)) {
        return ['id' => null, 'type' => null];
    }
    
    $parts = explode('|', $location_value);
    if (count($parts) == 2) {
        return [
            'type' => $parts[0], // 'location' or 'yard'
            'id' => (int)$parts[1]
        ];
    }
    
    // Fallback for old format (just numeric ID)
    if (is_numeric($location_value)) {
        return [
            'type' => 'location', // Default to location table
            'id' => (int)$location_value
        ];
    }
    
    return ['id' => null, 'type' => null];
}

// Helper function to upload images
function uploadImage($tmp_name, $upload_dir, $filename) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
    $file_info = getimagesize($tmp_name);
    
    if (!in_array($file_info['mime'], $allowed_types)) {
        throw new Exception("Invalid image type");
    }
    
    $extension = $file_info['mime'] == 'image/png' ? '.png' : '.jpg';
    $file_path = $upload_dir . $filename . '_' . time() . $extension;
    
    if (move_uploaded_file($tmp_name, $file_path)) {
        return $file_path;
    }
    
    throw new Exception("Failed to upload image");
}

// Updated vehicle assignment function with vendor workflow
function handleVehicleAssignment($pdo, $data) {
    $booking_id = $data['booking_id'];
    $assignments = $data['assignments'] ?? [];
    
    foreach ($assignments as $container_id => $assignment) {
        $vehicle_type = $assignment['vehicle_type']; // 'owned' or 'vendor'
        
        if ($vehicle_type === 'owned') {
            // Handle owned vehicles (existing logic)
            $vehicle_id = $assignment['vehicle_id'];
            
            $stmt = $pdo->prepare("
                UPDATE booking_containers 
                SET assigned_vehicle_id = ?,
                    vehicle_type = 'owned',
                    assignment_status = 'assigned',
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$vehicle_id, $_SESSION['user_id'], $container_id]);
            
            $trip_id = createTripForContainer($pdo, $booking_id, $container_id, 'owned', $vehicle_id, null);
            
            $stmt = $pdo->prepare("
                UPDATE booking_containers 
                SET trip_id = ?, assignment_status = 'trip_created'
                WHERE id = ?
            ");
            $stmt->execute([$trip_id, $container_id]);
            
        } elseif ($vehicle_type === 'vendor') {
            // Handle vendor vehicles (new workflow)
            $vendor_id = $assignment['vendor_id'];
            $vehicle_number = $assignment['vehicle_number'];
            $vendor_vehicle_id = $assignment['vendor_vehicle_id'] ?? null;
            $vendor_rate = $assignment['vendor_rate'] ?? 0;
            
            // Get vehicle number from vendor_vehicles table if vendor_vehicle_id is provided
            $actual_vehicle_number = $vehicle_number; // Default to the entered vehicle number
            if ($vendor_vehicle_id) {
                $stmt = $pdo->prepare("SELECT vehicle_number FROM vendor_vehicles WHERE id = ? AND vendor_id = ?");
                $stmt->execute([$vendor_vehicle_id, $vendor_id]);
                $vendor_vehicle = $stmt->fetch();
                if ($vendor_vehicle) {
                    $actual_vehicle_number = $vendor_vehicle['vehicle_number'];
                }
            }
            
            // Create a vendor vehicle assignment record
            $stmt = $pdo->prepare("
                INSERT INTO vendor_vehicle_assignments (
                    booking_id, container_id, vendor_id, 
                    daily_rate, assignment_status, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'pending_confirmation', ?, NOW())
            ");
            $stmt->execute([$booking_id, $container_id, $vendor_id, $vendor_rate, $_SESSION['user_id']]);
            $assignment_id = $pdo->lastInsertId();
            
            // Update container with vendor assignment info and rate
            $stmt = $pdo->prepare("
                UPDATE booking_containers 
                SET vendor_assignment_id = ?,
                    vehicle_type = 'vendor',
                    assignment_status = 'vendor_assigned',
                    rate = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$assignment_id, $vendor_rate, $_SESSION['user_id'], $container_id]);
            
            // If vendor_vehicle_id exists, update the vendor vehicle status
            if ($vendor_vehicle_id) {
                $stmt = $pdo->prepare("
                    UPDATE vendor_vehicles 
                    SET status = 'in_use', updated_at = NOW()
                    WHERE id = ? AND vendor_id = ?
                ");
                $stmt->execute([$vendor_vehicle_id, $vendor_id]);
            }
            
            // Send email to vendor
            sendVendorAssignmentEmail($pdo, $assignment_id);
            
            // Create trip with vendor details
            $trip_id = createVendorTripForContainer($pdo, $booking_id, $container_id, $vendor_id, $actual_vehicle_number, $vendor_rate);
            
            $stmt = $pdo->prepare("
                UPDATE booking_containers 
                SET trip_id = ?, assignment_status = 'trip_created'
                WHERE id = ?
            ");
            $stmt->execute([$trip_id, $container_id]);
        }
    }
    
    // Check completion status (existing logic)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN (assigned_vehicle_id IS NOT NULL OR vendor_assignment_id IS NOT NULL) THEN 1 ELSE 0 END) as assigned
        FROM booking_containers 
        WHERE booking_id = ?
    ");
    $stmt->execute([$booking_id]);
    $counts = $stmt->fetch();
    
    if ($counts['total'] == $counts['assigned'] && $counts['total'] > 0) {
        $stmt = $pdo->prepare("
            UPDATE bookings 
            SET status = 'confirmed', updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $booking_id]);
        
        $stmt = $pdo->prepare("
            INSERT INTO booking_activities (booking_id, activity_type, description, created_by, created_at)
            VALUES (?, 'booking_completed', ?, ?, NOW())
        ");
        $description = "Booking completed - all " . $counts['total'] . " containers assigned and trips created";
        $stmt->execute([$booking_id, $description, $_SESSION['user_id']]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE bookings 
            SET status = 'partially_fulfilled', updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $booking_id]);
    }
}

// Helper function to get location display name
function getLocationDisplayName($pdo, $location_id, $location_type) {
    if (!$location_id || !$location_type) {
        return 'Unknown Location';
    }
    
    try {
        if ($location_type === 'yard') {
            $stmt = $pdo->prepare("SELECT yard_name FROM yard_locations WHERE id = ?");
            $stmt->execute([$location_id]);
            $result = $stmt->fetch();
            return $result ? $result['yard_name'] : 'Unknown Location';
        } elseif ($location_type === 'location') {
            $stmt = $pdo->prepare("SELECT location FROM location WHERE id = ?");
            $stmt->execute([$location_id]);
            $result = $stmt->fetch();
            return $result ? $result['location'] : 'Unknown Location';
        }
    } catch (Exception $e) {
        error_log("Error getting location display name: " . $e->getMessage());
    }
    
    return 'Unknown Location';
}

// Function to create trip for vendor vehicle
function createVendorTripForContainer($pdo, $booking_id, $container_id, $vendor_id, $vehicle_number, $vendor_rate) {
    // Get booking and container details
    $stmt = $pdo->prepare("
        SELECT b.*, bc.*, c.client_name, v.company_name as vendor_name
        FROM bookings b 
        JOIN booking_containers bc ON b.id = bc.booking_id
        LEFT JOIN clients c ON b.client_id = c.id 
        LEFT JOIN vendors v ON v.id = ?
        WHERE b.id = ? AND bc.id = ?
    ");
    $stmt->execute([$vendor_id, $booking_id, $container_id]);
    $details = $stmt->fetch();
    
    if (!$details) {
        throw new Exception("Booking or container not found");
    }
    
    $reference_number = 'VTR-' . date('Y') . '-' . str_pad($booking_id . $details['container_sequence'], 6, '0', STR_PAD_LEFT) . '-' . $vehicle_number;
    
    // Get location names
    $from_location_name = getLocationDisplayName($pdo, $details['from_location_id'], $details['from_location_type']);
    $to_location_name = getLocationDisplayName($pdo, $details['to_location_id'], $details['to_location_type']);
    
    $stmt = $pdo->prepare("
        INSERT INTO trips (
            reference_number, trip_date, vehicle_id, vehicle_type, movement_type,
            client_id, container_type, container_size, from_location, to_location, 
            booking_number, is_vendor_vehicle, vendor_rate, status, 
            created_at, updated_at
        ) VALUES (?, CURDATE(), ?, 'vendor', 'export', ?, ?, ?, ?, ?, ?, 1, ?, 'pending', NOW(), NOW())
    ");
    
    // For vendor vehicles, use a special vehicle_id (0) and store vehicle number in reference
    $stmt->execute([
        $reference_number,
        0, // Special vehicle_id for vendor vehicles
        $details['client_id'],
        $details['container_type'],
        $details['container_type'],
        $from_location_name,
        $to_location_name,
        $details['booking_id'],
        $vendor_rate
    ]);
    
    return $pdo->lastInsertId();
}

// Function to send email to vendor about vehicle assignment
function sendVendorAssignmentEmail($pdo, $assignment_id) {
    try {
        // Get assignment details
        $stmt = $pdo->prepare("
            SELECT 
                va.*, v.company_name, v.email, v.contact_person,
                b.booking_id, bc.container_sequence, bc.container_type,
                bc.container_number_1, c.client_name,
                u.full_name as assigned_by
            FROM vendor_vehicle_assignments va
            JOIN vendors v ON va.vendor_id = v.id
            JOIN bookings b ON va.booking_id = b.id
            JOIN booking_containers bc ON va.container_id = bc.id
            JOIN clients c ON b.client_id = c.id
            JOIN users u ON va.created_by = u.id
            WHERE va.id = ?
        ");
        $stmt->execute([$assignment_id]);
        $assignment = $stmt->fetch();
        
        if (!$assignment || empty($assignment['email'])) {
            error_log("No email found for vendor assignment ID: $assignment_id");
            return false;
        }
        
        // Get location details
        $stmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN bc.from_location_type = 'yard' THEN yl_from.yard_name
                    WHEN bc.from_location_type = 'location' THEN loc_from.location
                    ELSE 'Not specified'
                END as from_location,
                CASE 
                    WHEN bc.to_location_type = 'yard' THEN yl_to.yard_name
                    WHEN bc.to_location_type = 'location' THEN loc_to.location
                    ELSE 'Not specified'
                END as to_location
            FROM booking_containers bc
            LEFT JOIN location loc_from ON bc.from_location_id = loc_from.id AND bc.from_location_type = 'location'
            LEFT JOIN location loc_to ON bc.to_location_id = loc_to.id AND bc.to_location_type = 'location'
            LEFT JOIN yard_locations yl_from ON bc.from_location_id = yl_from.id AND bc.from_location_type = 'yard'
            LEFT JOIN yard_locations yl_to ON bc.to_location_id = yl_to.id AND bc.to_location_type = 'yard'
            WHERE bc.id = ?
        ");
        $stmt->execute([$assignment['container_id']]);
        $locations = $stmt->fetch();
        
        // Prepare email content
        $subject = "Vehicle Assignment Request - Booking #{$assignment['booking_id']}";
        
        $email_body = "
        <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <h2 style='color: #0d9488;'>Vehicle Assignment Request</h2>
            
            <p>Dear {$assignment['contact_person']},</p>
            
            <p>We have assigned your company a vehicle for transportation services. Please find the details below:</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: #0d9488; margin-top: 0;'>Assignment Details</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'><strong>Booking Reference:</strong></td><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'>#{$assignment['booking_id']}</td></tr>
                    <tr><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'><strong>Client:</strong></td><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'>{$assignment['client_name']}</td></tr>
                    <tr><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'><strong>Container:</strong></td><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'>#{$assignment['container_sequence']} - {$assignment['container_number_1']}</td></tr>
                    <tr><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'><strong>Container Type:</strong></td><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'>{$assignment['container_type']}</td></tr>
                    <tr><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'><strong>Vehicle Number:</strong></td><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'>{$assignment['vehicle_number']}</td></tr>
                    <tr><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'><strong>Daily Rate:</strong></td><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'>$" . number_format($assignment['daily_rate'], 2) . "</td></tr>
                </table>
            </div>
            
            <div style='background: #e6fffa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: #0d9488; margin-top: 0;'>Route Information</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'><strong>From Location:</strong></td><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'>{$locations['from_location']}</td></tr>
                    <tr><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'><strong>To Location:</strong></td><td style='padding: 8px 0; border-bottom: 1px solid #ddd;'>{$locations['to_location']}</td></tr>
                </table>
            </div>
            
            <div style='background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                <h4 style='margin-top: 0; color: #856404;'>Next Steps:</h4>
                <ul style='color: #856404;'>
                    <li>Please confirm availability of the specified vehicle</li>
                    <li>Ensure driver has all necessary documents</li>
                    <li>Contact our operations team for pickup timing</li>
                    <li>Vehicle should be ready for dispatch as per schedule</li>
                </ul>
            </div>
            
            <p>Assigned by: <strong>{$assignment['assigned_by']}</strong><br>
            Assignment Date: <strong>" . date('F d, Y H:i', strtotime($assignment['created_at'])) . "</strong></p>
            
            <p>If you have any questions or concerns, please contact our operations team immediately.</p>
            
            <p>Best regards,<br>
            Operations Team</p>
        </body>
        </html>
        ";
        
        // Email headers
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: operations@yourcompany.com" . "\r\n"; // Replace with your company email
        
        // Send email
        $email_sent = mail($assignment['email'], $subject, $email_body, $headers);
        
        if ($email_sent) {
            // Log successful email
            $stmt = $pdo->prepare("
                UPDATE vendor_vehicle_assignments 
                SET email_sent_at = NOW(), email_status = 'sent'
                WHERE id = ?
            ");
            $stmt->execute([$assignment_id]);
            
            error_log("Vendor assignment email sent successfully to: " . $assignment['email']);
            return true;
        } else {
            error_log("Failed to send vendor assignment email to: " . $assignment['email']);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Error sending vendor assignment email: " . $e->getMessage());
        return false;
    }
}
// Add this function to better determine booking status and available actions
function getBookingStatusInfo($booking) {
    $total_containers = $booking['no_of_containers'] ?? 0;
    $filled_containers = $booking['filled_containers'] ?? 0;
    $assigned_containers = $booking['assigned_containers'] ?? 0;
    
    // Count containers ready for vehicle assignment (have container number, from location, and to location)
    $ready_for_assignment = 0;
    if (isset($booking['containers']) && is_array($booking['containers'])) {
        foreach ($booking['containers'] as $container) {
            $has_valid_from_location = $container['from_location_name'] && 
                $container['from_location_name'] !== 'Unknown Location' && 
                trim($container['from_location_name']) !== '';
            $has_valid_to_location = $container['to_location_name'] && 
                $container['to_location_name'] !== 'Unknown Location' && 
                trim($container['to_location_name']) !== '';
            
            if ($container['container_number_1'] && $has_valid_from_location && $has_valid_to_location) {
                $ready_for_assignment++;
            }
        }
    }
    
    $status_info = [
        'can_acknowledge' => false,
        'can_contact_l1' => false,
        'can_update_containers' => false,
        'can_assign_vehicles' => false,
        'is_complete' => false,
        'progress_message' => '',
        'next_action' => ''
    ];
    
    switch ($booking['status']) {
        case 'pending':
            $status_info['can_acknowledge'] = true;
            $status_info['next_action'] = 'Acknowledge booking to start processing';
            break;
            
        case 'being_addressed':
            $status_info['can_contact_l1'] = true;
            $status_info['next_action'] = 'Contact L1 supervisor for container details';
            break;
            
        case 'awaiting_containers':
            $status_info['can_update_containers'] = true;
            $status_info['progress_message'] = "Container details: {$filled_containers}/{$total_containers} completed";
            $status_info['next_action'] = 'Update container details as they become available';
            break;
            
        case 'containers_updated':
            $status_info['can_update_containers'] = true; // Allow further updates if needed
            $status_info['can_assign_vehicles'] = ($ready_for_assignment > 0);
            $status_info['progress_message'] = "Ready for vehicle assignment: {$ready_for_assignment}/{$total_containers} containers have all required details (container number, from/to locations)";
            $status_info['next_action'] = $ready_for_assignment > 0 ? 'Assign vehicles to containers' : 'Complete container details first';
            break;
            
        case 'partially_fulfilled':
            $status_info['can_update_containers'] = true; // Allow container updates for remaining
            $status_info['can_assign_vehicles'] = ($ready_for_assignment > $assigned_containers);
            $status_info['progress_message'] = "Vehicles assigned: {$assigned_containers}/{$total_containers} containers. Ready for assignment: {$ready_for_assignment} containers";
            $status_info['next_action'] = ($ready_for_assignment > $assigned_containers) ? 'Assign vehicles to remaining containers' : 'Complete container details first';
            break;
            
        case 'confirmed':
            $status_info['is_complete'] = true;
            $status_info['progress_message'] = "All containers assigned and trips created ({$assigned_containers}/{$total_containers})";
            $status_info['next_action'] = 'Booking completed successfully';
            break;
    }
    
    return $status_info;
}

// Function to create trip for assigned container
function createTripForContainer($pdo, $booking_id, $container_id, $vehicle_type, $vehicle_id, $vendor_rate) {
    // Get booking and container details
    $stmt = $pdo->prepare("
        SELECT b.*, bc.*, c.client_name, bc.from_location_id, bc.to_location_id
        FROM bookings b 
        JOIN booking_containers bc ON b.id = bc.booking_id
        LEFT JOIN clients c ON b.client_id = c.id 
        WHERE b.id = ? AND bc.id = ?
    ");
    $stmt->execute([$booking_id, $container_id]);
    $details = $stmt->fetch();
    
    if (!$details) {
        throw new Exception("Booking or container not found");
    }
    
    // Generate reference number
    $reference_number = 'TR-' . date('Y') . '-' . str_pad($booking_id . $details['container_sequence'], 6, '0', STR_PAD_LEFT);
    
    // Determine locations (use container-specific or fall back to booking default)
    $from_location = $details['from_location_id'] ?? $details['from_location_id'];
    $to_location = $details['to_location_id'] ?? $details['to_location_id'];
    
    // Get location names
    $from_location_name = '';
    $to_location_name = '';
    
    if ($from_location) {
        $stmt = $pdo->prepare("SELECT location FROM location WHERE id = ?");
        $stmt->execute([$from_location]);
        $result = $stmt->fetch();
        $from_location_name = $result ? $result['location'] : '';
    }
    
    if ($to_location) {
        $stmt = $pdo->prepare("SELECT location FROM location WHERE id = ?");
        $stmt->execute([$to_location]);
        $result = $stmt->fetch();
        $to_location_name = $result ? $result['location'] : '';
    }
    
    // Create trip with vendor details if applicable
    $stmt = $pdo->prepare("
        INSERT INTO trips (
            reference_number, trip_date, vehicle_id, vehicle_type, movement_type,
            client_id, container_type, container_size, from_location, to_location, 
            booking_number, is_vendor_vehicle, vendor_rate, status, 
            created_at, updated_at
        ) VALUES (?, CURDATE(), ?, ?, 'export', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
    ");
    
    $is_vendor_vehicle = ($vehicle_type === 'vendor') ? 1 : 0;
    
    $stmt->execute([
        $reference_number,
        $vehicle_id,
        $vehicle_type,
        $details['client_id'],
        $details['container_type'],
        $details['container_type'], // Using same value for size
        $from_location_name,
        $to_location_name,
        $details['booking_id'],
        $is_vendor_vehicle,
        $vendor_rate
    ]);
    
    return $pdo->lastInsertId();
}

// Get pending bookings - corrected query without using the view
$pending_bookings = [];
$stmt = $pdo->prepare("
    SELECT 
        b.id,
        b.booking_id,
        b.status,
        COALESCE(b.no_of_containers, 0) as no_of_containers, -- Explicitly handle NULL
        b.created_at, -- Ensure created_at is selected
        c.client_name,
        c.client_code,
        loc_from.location as from_location_name,
        loc_to.location as to_location_name,
        creator.full_name as created_by_name,
        creator.role as creator_role,
        ack_user.full_name as acknowledged_by_name,
        (SELECT COUNT(*) FROM booking_containers bc WHERE bc.booking_id = b.id AND (bc.assigned_vehicle_id IS NOT NULL OR bc.vendor_assignment_id IS NOT NULL)) as assigned_containers,
        (SELECT COUNT(*) FROM booking_containers bc WHERE bc.booking_id = b.id AND bc.container_number_1 IS NOT NULL AND bc.container_number_1 != '' AND bc.from_location_id IS NOT NULL AND bc.to_location_id IS NOT NULL) as filled_containers,
        (SELECT COUNT(*) FROM booking_containers bc WHERE bc.booking_id = b.id AND bc.container_image_1 IS NOT NULL) as containers_with_images,
        (SELECT COUNT(*) FROM booking_containers bc WHERE bc.booking_id = b.id AND bc.trip_id IS NOT NULL) as containers_with_trips
    FROM bookings b
    LEFT JOIN clients c ON b.client_id = c.id
    LEFT JOIN location loc_from ON b.from_location_id = loc_from.id
    LEFT JOIN location loc_to ON b.to_location_id = loc_to.id
    LEFT JOIN users creator ON b.created_by = creator.id
    LEFT JOIN users ack_user ON b.acknowledged_by = ack_user.id
    WHERE b.status IN ('pending', 'being_addressed', 'awaiting_containers', 'containers_updated', 'partially_fulfilled')
    GROUP BY b.id, b.booking_id
    ORDER BY 
        CASE b.status 
            WHEN 'being_addressed' THEN 1
            WHEN 'awaiting_containers' THEN 2
            WHEN 'containers_updated' THEN 3
            WHEN 'partially_fulfilled' THEN 4
            ELSE 5
        END,
        b.created_at ASC
");
$stmt->execute();
$pending_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Update the loop to handle missing keys
foreach ($pending_bookings as &$booking) {
    // Fetch existing containers with proper location resolution
    $stmt = $pdo->prepare("
        SELECT bc.*, 
               -- From location handling based on type
               CASE 
                   WHEN bc.from_location_type = 'yard' THEN yl_from.yard_name
                   WHEN bc.from_location_type = 'location' THEN loc_from.location
                   ELSE 'Unknown Location'
               END as from_location_name,
               bc.from_location_type,
               -- To location handling based on type  
               CASE 
                   WHEN bc.to_location_type = 'yard' THEN yl_to.yard_name
                   WHEN bc.to_location_type = 'location' THEN loc_to.location
                   ELSE 'Unknown Location'
               END as to_location_name,
               bc.to_location_type,
               v.vehicle_number as owned_vehicle_number,
               vv.vehicle_number as vendor_vehicle_number,
               t.reference_number as trip_reference,
               -- Vendor assignment information
               va.vendor_id,
               va.vehicle_number as vendor_assignment_vehicle_number,
               va.daily_rate as vendor_daily_rate,
               ven.company_name as vendor_company_name
        FROM booking_containers bc
        -- Left joins for regular locations
        LEFT JOIN location loc_from ON bc.from_location_id = loc_from.id AND bc.from_location_type = 'location'
        LEFT JOIN location loc_to ON bc.to_location_id = loc_to.id AND bc.to_location_type = 'location'
        -- Left joins for yard locations
        LEFT JOIN yard_locations yl_from ON bc.from_location_id = yl_from.id AND bc.from_location_type = 'yard'
        LEFT JOIN yard_locations yl_to ON bc.to_location_id = yl_to.id AND bc.to_location_type = 'yard'
        -- Vehicle and trip joins
        LEFT JOIN vehicles v ON bc.assigned_vehicle_id = v.id AND bc.vehicle_type = 'owned'
        LEFT JOIN vendor_vehicles vv ON bc.assigned_vehicle_id = vv.id AND bc.vehicle_type = 'vendor'
        LEFT JOIN trips t ON bc.trip_id = t.id
        -- Vendor assignment joins
        LEFT JOIN vendor_vehicle_assignments va ON bc.vendor_assignment_id = va.id
        LEFT JOIN vendors ven ON va.vendor_id = ven.id
        WHERE bc.booking_id = ? 
        ORDER BY bc.container_sequence
    ");
    $stmt->execute([$booking['id']]);
    $existing_containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $booking['containers'] = $existing_containers;
    $booking['containers_json'] = json_encode([
        'total_containers' => $booking['no_of_containers'] ?? 0,
        'existing_containers' => array_map(function($container) {
            return [
                'id' => $container['id'],
                'container_sequence' => $container['container_sequence'],
                'container_type' => $container['container_type'],
                'container_number_1' => $container['container_number_1'],
                'container_number_2' => $container['container_number_2'],
                'from_location_id' => $container['from_location_id'],
                'from_location_type' => $container['from_location_type'],
                'from_location_name' => $container['from_location_name'],
                'to_location_id' => $container['to_location_id'],
                'to_location_type' => $container['to_location_type'],
                'to_location_name' => $container['to_location_name'],
                'container_image_1' => $container['container_image_1'],
                'container_image_2' => $container['container_image_2'],
                'assigned_vehicle_id' => $container['assigned_vehicle_id'],
                'vendor_assignment_id' => $container['vendor_assignment_id'],
                'vehicle_type' => $container['vehicle_type'],
                'trip_id' => $container['trip_id'],
                'assignment_status' => $container['assignment_status'],
                'trip_reference' => $container['trip_reference'],
                'vendor_id' => $container['vendor_id'],
                'vendor_assignment_vehicle_number' => $container['vendor_assignment_vehicle_number'],
                'vendor_daily_rate' => $container['vendor_daily_rate'],
                'vendor_company_name' => $container['vendor_company_name'],
                // Format location values for form fields
                'from_location_value' => $container['from_location_type'] ? 
                    $container['from_location_type'] . '|' . $container['from_location_id'] : '',
                'to_location_value' => $container['to_location_type'] ? 
                    $container['to_location_type'] . '|' . $container['to_location_id'] : ''
            ];
        }, $existing_containers),
        'booking_id' => $booking['booking_id'],
        'id' => $booking['id']
    ]);
}
// Get statistics
$stats = [
    'pending' => count(array_filter($pending_bookings, fn($b) => $b['status'] === 'pending')),
    'being_addressed' => count(array_filter($pending_bookings, fn($b) => $b['status'] === 'being_addressed')),
    'awaiting_containers' => count(array_filter($pending_bookings, fn($b) => $b['status'] === 'awaiting_containers')),
    'ready_to_assign' => count(array_filter($pending_bookings, fn($b) => $b['status'] === 'containers_updated')),
    'partially_fulfilled' => count(array_filter($pending_bookings, fn($b) => $b['status'] === 'partially_fulfilled')),
    'pending_approval' => 0 // No longer needed since vendor details go directly to trips
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Requests - L2 Supervisor</title>
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
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-pending { background-color: #fef3c7; color: #92400e; }
        .status-being_addressed { background-color: #dbeafe; color: #1e40af; }
        .status-awaiting_containers { background-color: #fde68a; color: #d97706; }
        .status-containers_updated { background-color: #c7f59b; color: #365314; }
        .status-partially_fulfilled { background-color: #e0e7ff; color: #5b21b6; }
        .status-pending_vendor_approval { background-color: #fecaca; color: #991b1b; }
        .status-confirmed { background-color: #d1fae5; color: #065f46; }
        
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
        
        .btn-primary { background-color: #0d9488; color: white; }
        .btn-primary:hover { background-color: #0f766e; }
        .btn-secondary { background-color: #6b7280; color: white; }
        .btn-secondary:hover { background-color: #4b5563; }
        .btn-success { background-color: #059669; color: white; }
        .btn-success:hover { background-color: #047857; }
        .btn-warning { background-color: #d97706; color: white; }
        .btn-warning:hover { background-color: #b45309; }
        .btn-danger { background-color: #dc2626; color: white; }
        .btn-danger:hover { background-color: #b91c1c; }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 2% auto;
            border-radius: 12px;
            width: 95%;
            max-width: 800px;
            max-height: 95vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .search-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }
        
        .search-dropdown-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: all 0.2s ease;
        }
        
        .search-dropdown-item:hover {
            background-color: #f0fdfa;
        }
        
        .search-dropdown-item:last-child {
            border-bottom: none;
        }
        
        .container-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }
        
        .container-card.has-image {
            border-color: #10b981;
            background-color: #ecfdf5;
        }
        
        .container-card.assigned {
            border-color: #3b82f6;
            background-color: #eff6ff;
        }
        
        .priority-urgent {
            border-left: 4px solid #dc2626;
        }
        
        .priority-high {
            border-left: 4px solid #f59e0b;
        }
        
        .priority-normal {
            border-left: 4px solid #10b981;
        }
        
        .step-indicator {
            display: flex;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        
        .step-number {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .step.active .step-number {
            background-color: #0d9488;
            color: white;
        }
        
        .step.completed .step-number {
            background-color: #10b981;
            color: white;
        }
        
        .step.pending .step-number {
            background-color: #e5e7eb;
            color: #6b7280;
        }
        
        .step-line {
            position: absolute;
            top: 16px;
            left: 50%;
            width: 100%;
            height: 2px;
            background-color: #e5e7eb;
            z-index: -1;
        }
        
        .step.completed .step-line {
            background-color: #10b981;
        }
        
        .file-upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .file-upload-area:hover {
            border-color: #0d9488;
            background-color: #f0fdfa;
        }
        
        .file-upload-area.dragover {
            border-color: #0d9488;
            background-color: #f0fdfa;
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen flex">
        <!-- Sidebar Navigation -->
        <?php include 'sidebar_navigation.php'; ?>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Pending Requests</h1>
                        <p class="text-sm text-gray-600 mt-1">Process booking requests step by step</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div class="bg-teal-50 px-4 py-2 rounded-lg">
                            <span class="text-sm font-medium text-teal-800"><?= ucfirst(str_replace('_', ' ', $user_role)) ?> Dashboard</span>
                        </div>
                        <button onclick="refreshPage()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">
                            <i class="fas fa-sync-alt mr-2"></i>Refresh
                        </button>
                    </div>
                </div>
            </header>

            <!-- Messages -->
            <?php if ($success_message): ?>
                <div class="mx-6 mt-6 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg animate-fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?= htmlspecialchars($success_message) ?>
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

            <!-- Statistics Cards -->
            <div class="mx-6 mt-6 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="bg-white rounded-lg shadow-soft p-4 text-center">
                    <div class="text-2xl font-bold text-yellow-600"><?= $stats['pending'] ?></div>
                    <div class="text-xs text-gray-600">New Requests</div>
                </div>
                <div class="bg-white rounded-lg shadow-soft p-4 text-center">
                    <div class="text-2xl font-bold text-blue-600"><?= $stats['being_addressed'] ?></div>
                    <div class="text-xs text-gray-600">In Progress</div>
                </div>
                <div class="bg-white rounded-lg shadow-soft p-4 text-center">
                    <div class="text-2xl font-bold text-orange-600"><?= $stats['awaiting_containers'] ?></div>
                    <div class="text-xs text-gray-600">Awaiting Info</div>
                </div>
                <div class="bg-white rounded-lg shadow-soft p-4 text-center">
                    <div class="text-2xl font-bold text-green-600"><?= $stats['ready_to_assign'] ?></div>
                    <div class="text-xs text-gray-600">Ready to Assign</div>
                </div>
                <div class="bg-white rounded-lg shadow-soft p-4 text-center">
                    <div class="text-2xl font-bold text-purple-600"><?= $stats['partially_fulfilled'] ?></div>
                    <div class="text-xs text-gray-600">Partial</div>
                </div>
                <div class="bg-white rounded-lg shadow-soft p-4 text-center">
                    <div class="text-2xl font-bold text-red-600"><?= $stats['pending_approval'] ?></div>
                    <div class="text-xs text-gray-600">Need Approval</div>
                </div>
            </div>

            <!-- Main Content -->
            <main class="flex-1 p-6 overflow-auto">
                <div class="max-w-7xl mx-auto">
                    <?php if (empty($pending_bookings)): ?>
                        <!-- No Pending Requests -->
                        <div class="bg-white rounded-xl shadow-soft p-12 text-center">
                            <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6">
                                <i class="fas fa-clipboard-check text-3xl text-gray-400"></i>
                            </div>
                            <h3 class="text-xl font-semibold text-gray-900 mb-2">All Caught Up!</h3>
                            <p class="text-gray-600 mb-6">No pending requests to process right now.</p>
                            <a href="dashboard.php" class="btn-enhanced btn-primary">
                                <i class="fas fa-tachometer-alt mr-2"></i>
                                Go to Dashboard
                            </a>
                        </div>
                    <?php else: ?>
                        <!-- Pending Requests List -->
                        <div class="space-y-6">
                            <?php foreach ($pending_bookings as $booking): ?>
                                <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect priority-normal booking-card" data-booking-id="<?= $booking['id'] ?>">
                                    <!-- Booking Header -->
                                    <div class="flex justify-between items-start mb-6">
                                        <div>
                                            <h3 class="text-xl font-bold text-gray-900 mb-2">
                                                Booking #<?= htmlspecialchars($booking['booking_id']) ?>
                                            </h3>
                                            <div class="flex flex-wrap gap-2 mb-3">
                                                <span class="status-badge status-<?= $booking['status'] ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $booking['status'])) ?>
                                                </span>
                                                <span class="px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-medium">
                                                    <?= $booking['no_of_containers'] ?> Container<?= $booking['no_of_containers'] > 1 ? 's' : '' ?>
                                                </span>
                                                <?php if ($booking['acknowledged_by_name']): ?>
                                                    <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-medium">
                                                        Working: <?= htmlspecialchars($booking['acknowledged_by_name']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-sm text-gray-500">Created by</p>
                                            <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['created_by_name']) ?></p>
                                            <p class="text-xs text-gray-500"><?= ucfirst(str_replace('_', ' ', $booking['creator_role'])) ?></p>
                                            <p class="text-xs text-gray-500 mt-1"><?= date('M d, Y H:i', strtotime($booking['created_at'])) ?></p>
                                        </div>
                                    </div>

                                    <!-- Step Indicator -->
                                    <div class="step-indicator">
                                        <div class="step <?= in_array($booking['status'], ['pending']) ? 'active' : 'completed' ?>">
                                            <div class="step-number">1</div>
                                            <div class="text-xs">Acknowledge</div>
                                            <div class="step-line"></div>
                                        </div>
                                        <div class="step <?= $booking['status'] === 'awaiting_containers' ? 'active' : ($booking['status'] === 'pending' ? 'pending' : 'completed') ?>">
                                            <div class="step-number">2</div>
                                            <div class="text-xs">Get Details</div>
                                            <div class="step-line"></div>
                                        </div>
                                        <div class="step <?= $booking['status'] === 'containers_updated' ? 'active' : (in_array($booking['status'], ['pending', 'being_addressed', 'awaiting_containers']) ? 'pending' : 'completed') ?>">
                                            <div class="step-number">3</div>
                                            <div class="text-xs">Assign Vehicles</div>
                                            <div class="step-line"></div>
                                        </div>
                                        <div class="step <?= $booking['status'] === 'partially_fulfilled' ? 'active' : (in_array($booking['status'], ['pending', 'being_addressed', 'awaiting_containers', 'containers_updated']) ? 'pending' : 'completed') ?>">
                                            <div class="step-number">4</div>
                                            <div class="text-xs">Partially Fulfilled</div>
                                            <div class="step-line"></div>
                                        </div>
                                        <div class="step <?= $booking['status'] === 'confirmed' ? 'active' : ($booking['status'] === 'partially_fulfilled' ? 'pending' : 'pending') ?>">
                                            <div class="step-number">5</div>
                                            <div class="text-xs">Completed</div>
                                        </div>
                                    </div>

                                    <!-- Booking Details -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                        <div>
                                            <h4 class="font-semibold text-gray-900 mb-3">Client Information</h4>
                                            <div class="space-y-2">
                                                <p><span class="text-gray-600">Client:</span> <span class="font-medium"><?= htmlspecialchars($booking['client_name']) ?></span></p>
                                                <p><span class="text-gray-600">Code:</span> <span class="font-medium"><?= htmlspecialchars($booking['client_code']) ?></span></p>
                                            </div>
                                        </div>
                                        
                                        
                                    </div>

                                    <!-- Container Details -->
                                    <div class="bg-white rounded-xl shadow-soft p-6">
										<input type="hidden" class="booking-containers-data" data-booking-id="<?= $booking['id'] ?>" value="<?= htmlspecialchars($booking['containers_json']) ?>">
										
										<div class="mb-6">
											<div class="flex items-center justify-between mb-4">
												<h4 class="text-lg font-semibold text-gray-900">Container Details</h4>
												<div class="flex items-center gap-4 text-sm">
													<div class="flex items-center gap-2">
														<span class="w-3 h-3 bg-blue-500 rounded-full"></span>
														<span class="text-gray-600">Assigned: <?= $booking['assigned_containers'] ?>/<?= $booking['no_of_containers'] ?? 0 ?></span>
													</div>
													<div class="flex items-center gap-2">
														<span class="w-3 h-3 bg-green-500 rounded-full"></span>
														<span class="text-gray-600">Filled: <?= $booking['filled_containers'] ?>/<?= $booking['no_of_containers'] ?? 0 ?></span>
													</div>
												</div>
											</div>
											
											<?php if (!empty($booking['containers'])): ?>
												<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
													<?php foreach ($booking['containers'] as $container): 
														// Check if container is ready for vehicle assignment
														$has_valid_from_location = $container['from_location_name'] && 
															$container['from_location_name'] !== 'Unknown Location' && 
															trim($container['from_location_name']) !== '';
														$has_valid_to_location = $container['to_location_name'] && 
															$container['to_location_name'] !== 'Unknown Location' && 
															trim($container['to_location_name']) !== '';
														$is_ready_for_assignment = $container['container_number_1'] && $has_valid_from_location && $has_valid_to_location;
													?>
														<div class="relative bg-white border-2 rounded-xl p-5 transition-all duration-200 hover:shadow-md <?= ($container['assigned_vehicle_id'] || $container['vendor_assignment_id']) ? 'border-blue-300 bg-blue-50' : ($is_ready_for_assignment ? 'border-green-300 bg-green-50' : 'border-gray-200') ?>">
															<!-- Container Header -->
															<div class="flex items-center justify-between mb-4">
																<div class="flex items-center gap-3">
																	<div class="w-10 h-10 bg-teal-600 text-white rounded-full flex items-center justify-center text-sm font-bold">
															<?= $container['container_sequence'] ?>
																	</div>
																	<div>
																		<h5 class="font-semibold text-gray-900"><?= $container['container_type'] ?: 'Not specified' ?></h5>
																		<p class="text-xs text-gray-500">Container <?= $container['container_sequence'] ?></p>
																	</div>
																</div>
																<?php if ($is_ready_for_assignment && !$container['assigned_vehicle_id'] && !$container['vendor_assignment_id']): ?>
																	<span class="px-3 py-1 bg-green-100 text-green-800 text-xs rounded-full font-medium">
																		<i class="fas fa-check mr-1"></i>Ready
														</span>
																<?php elseif ($container['assigned_vehicle_id'] || $container['vendor_assignment_id']): ?>
																	<span class="px-3 py-1 bg-blue-100 text-blue-800 text-xs rounded-full font-medium">
																		<i class="fas fa-truck mr-1"></i>Assigned
																	</span>
																<?php else: ?>
																	<span class="px-3 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full font-medium">
																		<i class="fas fa-clock mr-1"></i>Pending
																	</span>
																<?php endif; ?>
													</div>
															
															<!-- Container Details -->
															<div class="space-y-3">
																<div class="grid grid-cols-2 gap-3">
																	<div>
																		<p class="text-xs text-gray-500 mb-1">Container Number</p>
																		<p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($container['container_number_1'] ?: 'Pending') ?></p>
																	</div>
														<?php if ($container['container_number_2']): ?>
																		<div>
																			<p class="text-xs text-gray-500 mb-1">Number 2</p>
																			<p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($container['container_number_2']) ?></p>
																		</div>
														<?php endif; ?>
																</div>
																
																<div class="border-t pt-3">
																	<div class="space-y-2">
																		<div class="flex items-center gap-2">
																			<i class="fas fa-map-marker-alt text-gray-400 text-xs"></i>
																			<div>
																				<p class="text-xs text-gray-500">From</p>
																				<p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($container['from_location_name'] ?: 'Pending') ?></p>
																			</div>
																		</div>
																		<div class="flex items-center gap-2">
																			<i class="fas fa-flag-checkered text-gray-400 text-xs"></i>
																			<div>
																				<p class="text-xs text-gray-500">To</p>
																				<p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($container['to_location_name'] ?: 'Pending') ?></p>
																			</div>
																		</div>
																	</div>
																</div>
																
																<?php if ($container['assigned_vehicle_id'] || $container['vendor_assignment_id']): ?>
																	<div class="border-t pt-3 bg-gray-50 -mx-5 px-5 py-3 rounded-b-xl">
																		<div class="space-y-2">
																			<div class="flex items-center gap-2">
																				<i class="fas fa-truck text-blue-500 text-xs"></i>
																				<div>
																					<p class="text-xs text-gray-500">Vehicle</p>
																					<p class="text-sm font-medium text-gray-900">
														<?php if ($container['assigned_vehicle_id']): ?>
																							<?= htmlspecialchars($container['owned_vehicle_number'] ?: $container['vendor_vehicle_number']) ?> (<?= $container['vehicle_type'] ?>)
																						<?php elseif ($container['vendor_assignment_id']): ?>
																							<?= htmlspecialchars($container['vendor_assignment_vehicle_number'] ?: 'Vendor Vehicle') ?> (vendor)
																						<?php endif; ?>
																					</p>
																				</div>
																			</div>
																			<div class="flex items-center gap-2">
																				<i class="fas fa-route text-blue-500 text-xs"></i>
																				<div>
																					<p class="text-xs text-gray-500">Trip</p>
																					<p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($container['trip_reference'] ?: 'Not created') ?></p>
																				</div>
																			</div>
																			<?php if ($container['vendor_assignment_id']): ?>
																			<div class="flex items-center gap-2">
																				<i class="fas fa-building text-blue-500 text-xs"></i>
																				<div>
																					<p class="text-xs text-gray-500">Vendor</p>
																					<p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($container['vendor_company_name'] ?: 'Unknown Vendor') ?></p>
																				</div>
																			</div>
																			<div class="flex items-center gap-2">
																				<i class="fas fa-rupee-sign text-blue-500 text-xs"></i>
																				<div>
																					<p class="text-xs text-gray-500">Rate</p>
																					<p class="text-sm font-medium text-gray-900">₹<?= number_format($container['vendor_daily_rate'] ?: 0, 2) ?>/day</p>
																				</div>
																			</div>
																			<?php endif; ?>
																		</div>
																	</div>
														<?php endif; ?>
													</div>
												</div>
											<?php endforeach; ?>
										</div>
									<?php else: ?>
												<div class="text-center py-12">
													<div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
														<i class="fas fa-cube text-2xl text-gray-400"></i>
													</div>
													<h3 class="text-lg font-medium text-gray-900 mb-2">No Container Details Yet</h3>
													<p class="text-gray-500 mb-4">Container details will appear here once they are added.</p>
													<p class="text-sm text-gray-400">Expected: <?= $booking['no_of_containers'] ?? 0 ?> containers</p>
            </div>
        <?php endif; ?>
										</div>
								</div>

                                    <!-- Action Buttons -->
                                    <div class="flex flex-wrap gap-3 pt-4 border-t border-gray-200">
								<?php 
								$status_info = getBookingStatusInfo($booking);
								?>
    
							<!-- Progress Information -->
									<?php if ($status_info['progress_message']): ?>
										<div class="w-full bg-blue-50 border border-blue-200 rounded-lg p-3 mb-3">
											<div class="flex items-center justify-between">
												<div class="flex items-center">
													<i class="fas fa-info-circle text-blue-600 mr-2"></i>
													<span class="text-sm text-blue-800"><?= $status_info['progress_message'] ?></span>
												</div>
												<div class="text-xs text-blue-600">
													Next: <?= $status_info['next_action'] ?>
												</div>
											</div>
										</div>
									<?php endif; ?>

									<!-- Action Buttons -->
									<?php if ($status_info['can_acknowledge']): ?>
										<button onclick="acknowledgeBooking(<?= $booking['id'] ?>)" class="btn-enhanced btn-primary">
											<i class="fas fa-hand-paper mr-2"></i>
											Start Working
										</button>
									<?php endif; ?>

									<?php if ($status_info['can_contact_l1']): ?>
										<button onclick="openL1ContactModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['booking_id']) ?>')" class="btn-enhanced btn-warning">
											<i class="fas fa-phone mr-2"></i>
											Contact L1 for Details
										</button>
									<?php endif; ?>

									<?php if ($status_info['can_update_containers']): ?>
										<button onclick="openContainerUpdateModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['booking_id']) ?>', <?= $booking['status'] === 'partially_fulfilled' ? 'true' : 'false' ?>)" class="btn-enhanced btn-success">
											<i class="fas fa-upload mr-2"></i>
											<?= $booking['status'] === 'partially_fulfilled' ? 'Update Remaining Containers' : 'Update Container Details' ?>
										</button>
									<?php endif; ?>

									<?php if ($status_info['can_assign_vehicles']): ?>
										<button onclick="openVehicleAssignmentModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['booking_id']) ?>')" class="btn-enhanced btn-primary">
											<i class="fas fa-truck mr-2"></i>
											<?= $booking['status'] === 'partially_fulfilled' ? 'Assign Remaining Vehicles' : 'Assign Vehicles' ?>
										</button>
									<?php endif; ?>

									<?php if ($status_info['is_complete']): ?>
										<div class="w-full bg-green-50 border border-green-200 rounded-lg p-3">
											<div class="flex items-center">
												<i class="fas fa-check-circle text-green-600 mr-2"></i>
												<span class="text-sm text-green-800"><?= $status_info['progress_message'] ?></span>
											</div>
										</div>
									<?php endif; ?>

									<?php if ($booking['status'] === 'pending_vendor_approval'): ?>
										<div class="w-full bg-yellow-50 border border-yellow-200 rounded-lg p-3">
											<div class="flex items-center">
												<i class="fas fa-clock text-yellow-600 mr-2"></i>
												<span class="text-sm text-yellow-800">Waiting for manager approval on vendor rates</span>
											</div>
										</div>
									<?php endif; ?>

									<button onclick="viewBookingDetails(<?= $booking['id'] ?>)" class="btn-enhanced btn-secondary">
										<i class="fas fa-eye mr-2"></i>
										View Details
									</button>
								</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- L1 Contact Modal -->
    <div id="l1ContactModal" class="modal">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Contact L1 Supervisor</h3>
                    <button onclick="closeModal('l1ContactModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                        <span class="text-sm text-blue-800">Send booking details to L1 supervisor to get container images via WhatsApp</span>
                    </div>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="contact_l1">
                    <input type="hidden" name="booking_id" id="l1_booking_id">
                    
                    <div>
					<label class="block text-sm font-medium text-gray-700 mb-2">
						<i class="fas fa-user text-teal-600 mr-2"></i>L1 Supervisor *
					</label>
					<div class="relative">
						<input type="text" class="input-enhanced l1-search" 
							   placeholder="Search L1 supervisor by name or mobile..." 
							   onkeyup="searchL1Supervisors(this, 'l1_contact')">
						<input type="hidden" name="l1_contact" id="l1_contact_hidden">
						<div class="search-dropdown"></div>
					</div>
				</div>
									
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-comment text-teal-600 mr-2"></i>Message to Send
                        </label>
                        <textarea name="whatsapp_message" rows="4" class="input-enhanced" 
                                  placeholder="Hi, please send container images for booking..." readonly></textarea>
                        <p class="text-xs text-gray-500 mt-1">Message will be auto-generated based on booking details</p>
                    </div>
                    
                    <div class="flex gap-3 pt-4">
                        <button type="submit" class="btn-enhanced btn-primary flex-1">
                            <i class="fas fa-paper-plane mr-2"></i>Send WhatsApp Message
                        </button>
                        <button type="button" onclick="closeModal('l1ContactModal')" class="btn-enhanced btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Container Update Modal -->
				<div id="container-update-modal" class="modal hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
					<div class="modal-content bg-white rounded-lg p-6 max-w-4xl mx-auto mt-10">
						<div class="flex justify-between items-center border-b pb-3 mb-4">
							<h2 class="text-xl font-bold">Update Container Details</h2>
							<button onclick="closeModal('container-update-modal')" class="text-gray-500 hover:text-gray-700">
								<i class="fas fa-times"></i>
							</button>
						</div>
						<form id="container-update-form" enctype="multipart/form-data">
							<input type="hidden" name="action" value="update_container_details">
							<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
							<input type="hidden" name="booking_id" id="container_booking_id">
							<input type="hidden" name="total_containers" id="container_total_containers">
							<div id="container-forms-section"></div>
							<div class="mt-6 text-right">
								<button type="button" onclick="submitContainerForm()" class="btn-enhanced btn-success">
									<i class="fas fa-save mr-2"></i> Save Changes
								</button>
								<button type="button" onclick="closeModal('container-update-modal')" class="btn-enhanced btn-secondary ml-3">
									<i class="fas fa-times mr-2"></i> Cancel
								</button>
							</div>
						</form>
					</div>
				</div>

    <!-- Vehicle Assignment Modal -->
    <div id="vehicleAssignmentModal" class="modal">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Assign Vehicles to Containers</h3>
                    <button onclick="closeModal('vehicleAssignmentModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-green-600 mr-2"></i>
                        <span class="text-sm text-green-800">Assign vehicles to each container. Owned vehicles complete immediately, vendor vehicles need manager approval.</span>
                    </div>
                </div>
                
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="assign_vehicles">
                    <input type="hidden" name="booking_id" id="vehicle_booking_id">
                    
                    <div id="vehicle-assignment-section">
                        <!-- Vehicle assignment forms will be dynamically loaded here -->
                    </div>
                    
                    <div class="flex gap-3 pt-4 border-t border-gray-200">
                        <button type="submit" class="btn-enhanced btn-primary flex-1">
                            <i class="fas fa-truck mr-2"></i>Assign Vehicles
                        </button>
                        <button type="button" onclick="closeModal('vehicleAssignmentModal')" class="btn-enhanced btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Booking Details Modal -->
    <div id="bookingDetailsModal" class="modal">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Booking Details</h3>
                    <button onclick="closeModal('bookingDetailsModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div id="booking-details-content">
                    <!-- Booking details will be loaded here -->
                </div>
                
                <div class="flex gap-3 pt-4 border-t border-gray-200">
                    <button type="button" onclick="closeModal('bookingDetailsModal')" class="btn-enhanced btn-secondary">
                        <i class="fas fa-times mr-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>


<script>
    // Initialize search timeouts object to prevent undefined errors
    const searchTimeouts = {
        locations: null,
        vehicles: null,
        vendors: null,
        vendor_vehicles: null,
        l1_supervisors: null
    };

    // FIXED: Modal control functions - using style.display instead of classList
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'block';
            modal.classList.remove('hidden');
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
            modal.classList.add('hidden');
        }
    }

    // FIXED: Added missing acknowledge booking function
    function acknowledgeBooking(bookingId) {
        if (!confirm('Are you sure you want to start working on this booking?')) {
            return;
        }
        
        showLoading('Acknowledging booking...');
        
        const formData = new FormData();
        formData.append('booking_id', bookingId);
        
        fetch('?ajax=acknowledge_booking', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showNotification(data.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showNotification(data.message || 'Error acknowledging booking', 'error');
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            showNotification('Error acknowledging booking', 'error');
        });
    }

    // FIXED: Added missing L1 contact modal function
    function openL1ContactModal(bookingId, bookingRef) {
        document.getElementById('l1_booking_id').value = bookingId;
        
        // Auto-generate WhatsApp message
        const messageTextarea = document.querySelector('textarea[name="whatsapp_message"]');
        if (messageTextarea) {
            const message = `Hi, please send container images and details for Booking #${bookingRef}. 

Please provide:
- Container numbers
- Container types (20ft/40ft)
- Pickup and delivery locations
- Clear images of each container

Thank you!`;
            messageTextarea.value = message;
        }
        
        openModal('l1ContactModal');
    }

    // FIXED: Container update modal function
    function openContainerUpdateModal(bookingId, bookingRef, isRemainingContainers = false) {
        const modal = document.getElementById('container-update-modal');
        if (!modal) {
            console.error('Container update modal not found');
            return;
        }

        document.getElementById('container_booking_id').value = bookingId;
        const bookingCard = document.querySelector(`[data-booking-id="${bookingId}"]`);
        const containersDataInput = bookingCard ? bookingCard.querySelector('.booking-containers-data') : null;

        if (containersDataInput) {
            let containersData;
            try {
                containersData = JSON.parse(containersDataInput.value);
                document.getElementById('container_total_containers').value = containersData.total_containers || 0;
            } catch (e) {
                console.error('Error parsing container data:', e);
                document.getElementById('container_total_containers').value = 0;
            }
        }

        // Pass the excludeTripCreated parameter based on whether this is for remaining containers
        loadContainerForms(bookingId, isRemainingContainers);
        openModal('container-update-modal');
    }

    // Load container forms dynamically
function loadContainerForms(bookingId, excludeTripCreated = false) {
    const formsSection = document.getElementById('container-forms-section');
    if (!formsSection) return;
    
    // Update modal title based on whether we're excluding trip-created containers
    const modalTitle = document.querySelector('#container-update-modal h2');
    if (modalTitle) {
        modalTitle.textContent = excludeTripCreated ? 'Update Remaining Container Details' : 'Update Container Details';
    }

    const bookingCard = document.querySelector(`[data-booking-id="${bookingId}"]`);
    const containersDataInput = bookingCard ? bookingCard.querySelector('.booking-containers-data') : null;
    let containersData = { total_containers: 0, existing_containers: [] };

    if (containersDataInput) {
        try {
            containersData = JSON.parse(containersDataInput.value) || containersData;
        } catch (e) {
            console.error('Error parsing container data:', e);
        }
    }

    const totalContainers = containersData.total_containers || 0;
    let existingContainers = containersData.existing_containers || [];
    
    // Filter out containers with trips created if requested
    const originalContainerCount = existingContainers.length;
    if (excludeTripCreated) {
        existingContainers = existingContainers.filter(container => 
            !container.trip_id && container.assignment_status !== 'trip_created'
        );
    }
    
    let formsHTML = '';
    
    // Show info message if containers were filtered out
    if (excludeTripCreated && originalContainerCount > existingContainers.length) {
        const filteredCount = originalContainerCount - existingContainers.length;
        formsHTML += `
            <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                    <span class="text-sm text-blue-800">
                        ${filteredCount} container(s) with trips already created are hidden. Only remaining containers are shown below.
                    </span>
                </div>
            </div>
        `;
    }

    // Load existing containers with proper location value handling
    existingContainers.forEach((container, index) => {
        const key = container.id || `existing_${index}`;
        const isNumber1Readonly = container.container_number_1 ? 'readonly' : '';
        
        formsHTML += `
            <div class="container-form bg-gray-50 rounded-lg p-4 border border-gray-200 mb-4">
                <div class="flex items-center gap-2 mb-4">
                    <span class="w-8 h-8 bg-teal-600 text-white rounded-full flex items-center justify-center text-sm font-bold">${container.container_sequence}</span>
                    <h4 class="font-semibold text-gray-900">Container ${container.container_sequence}</h4>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Type</label>
                        <select name="containers[${key}][container_type]" class="input-enhanced" onchange="toggleContainerNumber2(this, '${key}')" style="-webkit-appearance: none; -moz-appearance: none; appearance: none; background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'m6 8 4 4 4-4\'/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; background-size: 16px; padding-right: 40px;">
                            <option value="">Select type</option>
                            <option value="20ft" ${container.container_type === '20ft' ? 'selected' : ''}>20ft</option>
                            <option value="40ft" ${container.container_type === '40ft' ? 'selected' : ''}>40ft</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Number 1</label>
                        <input type="text" name="containers[${key}][number1]" class="input-enhanced" placeholder="Enter container number" value="${container.container_number_1 || ''}" ${isNumber1Readonly}>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 container-number2-section" style="display: ${container.container_type === '20ft' ? 'block' : 'none'};">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Number 2 (optional for 20ft)</label>
                        <input type="text" name="containers[${key}][number2]" class="input-enhanced" placeholder="Enter second container number" value="${container.container_number_2 || ''}" ${isNumber1Readonly}>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">From Location</label>
                        <div class="relative">
                            <input type="text" class="input-enhanced location-search" placeholder="Search from location..." onkeyup="searchLocations(this, 'containers[${key}][from_location]')" value="${container.from_location_name || ''}">
                            <input type="hidden" name="containers[${key}][from_location]" value="${container.from_location_value || ''}">
                            <div class="search-dropdown"></div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">To Location</label>
                        <div class="relative">
                            <input type="text" class="input-enhanced location-search" placeholder="Search to location..." onkeyup="searchLocations(this, 'containers[${key}][to_location]')" value="${container.to_location_name || ''}">
                            <input type="hidden" name="containers[${key}][to_location]" value="${container.to_location_value || ''}">
                            <div class="search-dropdown"></div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Image 1</label>
                        <div class="file-upload-area" onclick="triggerFileUpload('container_${key}_image1')">
                            <input type="file" id="container_${key}_image1" name="container_images[container_${key}_image1]" accept="image/*" style="display: none;" onchange="handleFileSelect(this)">
                            <i class="fas fa-cloud-upload-alt text-2xl text-gray-400 mb-2"></i>
                            <p class="text-sm text-gray-600">Click to upload or drag image here</p>
                            ${container.container_image_1 ? '<p class="text-xs text-green-600">Existing image uploaded</p>' : ''}
                            <div class="file-preview mt-2" style="display: none;"></div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Image 2 (optional)</label>
                        <div class="file-upload-area" onclick="triggerFileUpload('container_${key}_image2')">
                            <input type="file" id="container_${key}_image2" name="container_images[container_${key}_image2]" accept="image/*" style="display: none;" onchange="handleFileSelect(this)">
                            <i class="fas fa-cloud-upload-alt text-2xl text-gray-400 mb-2"></i>
                            <p class="text-sm text-gray-600">Click to upload or drag image here</p>
                            ${container.container_image_2 ? '<p class="text-xs text-green-600">Existing image uploaded</p>' : ''}
                            <div class="file-preview mt-2" style="display: none;"></div>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="containers[${key}][id]" value="${container.id || ''}">
            </div>
        `;
    });

    // Only add new containers if not excluding trip-created containers (i.e., for initial setup)
    if (!excludeTripCreated) {
    const remaining = totalContainers - existingContainers.length;
    for (let i = 0; i < remaining; i++) {
        const key = `new_${i}`;
        const seq = existingContainers.length + i + 1;
        formsHTML += `
            <div class="container-form bg-gray-50 rounded-lg p-4 border border-gray-200 mb-4">
                <div class="flex items-center gap-2 mb-4">
                    <span class="w-8 h-8 bg-teal-600 text-white rounded-full flex items-center justify-center text-sm font-bold">${seq}</span>
                    <h4 class="font-semibold text-gray-900">Container ${seq} (New)</h4>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Type</label>
                            <select name="containers[${key}][container_type]" class="input-enhanced" onchange="toggleContainerNumber2(this, '${key}')" style="-webkit-appearance: none; -moz-appearance: none; appearance: none; background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'m6 8 4 4 4-4\'/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; background-size: 16px; padding-right: 40px;">
                            <option value="">Select type</option>
                            <option value="20ft">20ft</option>
                            <option value="40ft">40ft</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Number 1</label>
                        <input type="text" name="containers[${key}][number1]" class="input-enhanced" placeholder="Enter container number">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 container-number2-section" style="display: none;">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Number 2 (optional for 20ft)</label>
                        <input type="text" name="containers[${key}][number2]" class="input-enhanced" placeholder="Enter second container number">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">From Location</label>
                        <div class="relative">
                            <input type="text" class="input-enhanced location-search" placeholder="Search from location..." onkeyup="searchLocations(this, 'containers[${key}][from_location]')">
                            <input type="hidden" name="containers[${key}][from_location]">
                            <div class="search-dropdown"></div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">To Location</label>
                        <div class="relative">
                            <input type="text" class="input-enhanced location-search" placeholder="Search to location..." onkeyup="searchLocations(this, 'containers[${key}][to_location]')">
                            <input type="hidden" name="containers[${key}][to_location]">
                            <div class="search-dropdown"></div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Image 1</label>
                        <div class="file-upload-area" onclick="triggerFileUpload('container_${key}_image1')">
                            <input type="file" id="container_${key}_image1" name="container_images[container_${key}_image1]" accept="image/*" style="display: none;" onchange="handleFileSelect(this)">
                            <i class="fas fa-cloud-upload-alt text-2xl text-gray-400 mb-2"></i>
                            <p class="text-sm text-gray-600">Click to upload or drag image here</p>
                            <div class="file-preview mt-2" style="display: none;"></div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Image 2 (optional)</label>
                        <div class="file-upload-area" onclick="triggerFileUpload('container_${key}_image2')">
                            <input type="file" id="container_${key}_image2" name="container_images[container_${key}_image2]" accept="image/*" style="display: none;" onchange="handleFileSelect(this)">
                            <i class="fas fa-cloud-upload-alt text-2xl text-gray-400 mb-2"></i>
                            <p class="text-sm text-gray-600">Click to upload or drag image here</p>
                            <div class="file-preview mt-2" style="display: none;"></div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        }
    }

    formsSection.innerHTML = formsHTML;
    setupSearchInputs();
}
    // Toggle container number 2 based on type
    function toggleContainerNumber2(selectElement, key) {
        const section = selectElement.closest('.container-form').querySelector('.container-number2-section');
        const number2Input = section.querySelector(`input[name="containers[${key}][number2]"]`);
        if (selectElement.value === '20ft') {
            section.style.display = 'block';
            number2Input.disabled = false;
        } else {
            section.style.display = 'none';
            number2Input.disabled = true;
            number2Input.value = '';
        }
    }

    // FIXED: Vehicle assignment modal function
    function openVehicleAssignmentModal(bookingId, bookingRef) {
        document.getElementById('vehicle_booking_id').value = bookingId;
        loadVehicleAssignmentForms(bookingId);
        openModal('vehicleAssignmentModal');
    }

    // Load vehicle assignment forms
function loadVehicleAssignmentForms(bookingId) {
    const assignmentSection = document.getElementById('vehicle-assignment-section');
    if (!assignmentSection) return;

    const bookingCard = document.querySelector(`[data-booking-id="${bookingId}"]`);
    const containersDataInput = bookingCard ? bookingCard.querySelector('.booking-containers-data') : null;
    let containersData = { existing_containers: [] };

    if (containersDataInput) {
        try {
            containersData = JSON.parse(containersDataInput.value) || containersData;
        } catch (e) {
            console.error('Error parsing container data:', e);
        }
    }

    const containers = containersData.existing_containers || [];
    let formsHTML = '';

    containers.forEach((container, index) => {
        // Only show containers that have container number AND both from and to locations (not "Unknown Location")
        const hasValidFromLocation = container.from_location_name && 
                                    container.from_location_name !== 'Unknown Location' && 
                                    container.from_location_name.trim() !== '';
        const hasValidToLocation = container.to_location_name && 
                                  container.to_location_name !== 'Unknown Location' && 
                                  container.to_location_name.trim() !== '';
        
        // Filter out containers that are already assigned or have trips created
        const isAlreadyAssigned = container.assigned_vehicle_id || container.assignment_status === 'trip_created';
        const hasTripCreated = container.trip_id || container.assignment_status === 'trip_created';
        
        if (container.container_number_1 && hasValidFromLocation && hasValidToLocation && !isAlreadyAssigned && !hasTripCreated) {
            formsHTML += `
                <div class="assignment-form bg-gray-50 rounded-lg p-4 border border-gray-200 mb-4">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <span class="w-8 h-8 bg-teal-600 text-white rounded-full flex items-center justify-center text-sm font-bold">${container.container_sequence}</span>
                            <h4 class="font-semibold text-gray-900">Container ${container.container_sequence}</h4>
                            <span class="text-sm text-gray-600">(${container.container_type || 'Unknown'} - ${container.container_number_1})</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-4">
                        <!-- Vehicle Type Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Vehicle Type</label>
                            <select name="assignments[${container.id}][vehicle_type]" class="input-enhanced" onchange="handleVehicleTypeChange(this, ${container.id})" style="-webkit-appearance: none; -moz-appearance: none; appearance: none; background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'m6 8 4 4 4-4\'/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 12px center; background-size: 16px; padding-right: 40px;">
                                <option value="">Select type...</option>
                                <option value="owned">Owned Vehicle</option>
                                <option value="vendor">Vendor Vehicle</option>
                            </select>
                        </div>
                        
                        <!-- Owned Vehicle Section -->
                        <div id="owned-section-${container.id}" class="owned-vehicle-section" style="display: none;">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Search Owned Vehicle</label>
                                    <div class="relative">
                                        <input type="text" class="input-enhanced vehicle-search" placeholder="Search owned vehicle..." onkeyup="searchVehicles(this, 'assignments[${container.id}][vehicle_id]')">
                                        <input type="hidden" name="assignments[${container.id}][vehicle_id]">
                                        <div class="search-dropdown"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Vendor Vehicle Section -->
                        <div id="vendor-section-${container.id}" class="vendor-vehicle-section" style="display: none;">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Search Vendor *</label>
                                    <div class="relative">
                                        <input type="text" class="input-enhanced vendor-search" placeholder="Search vendor company..." onkeyup="searchVendors(this, 'assignments[${container.id}][vendor_id]')" onchange="handleVendorSelection(this, ${container.id})">
                                        <input type="hidden" name="assignments[${container.id}][vendor_id]">
                                        <div class="search-dropdown"></div>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Vehicle Number *</label>
                                    <div class="relative">
                                        <input type="text" class="input-enhanced vendor-vehicle-search" id="vehicle_number_${container.id}" name="assignments[${container.id}][vehicle_number]" placeholder="Enter or search vehicle number..." onkeyup="searchVendorVehicles(this, 'assignments[${container.id}][vendor_vehicle_id]')" disabled>
                                        <input type="hidden" name="assignments[${container.id}][vendor_vehicle_id]">
                                        <div class="search-dropdown"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Daily Rate *</label>
                                    <input type="number" id="vendor_rate_${container.id}" name="assignments[${container.id}][vendor_rate]" class="input-enhanced" placeholder="0.00" step="0.01" min="0" required disabled>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i> 
                        <span id="help-text-${container.id}">Please select a vehicle type to continue.</span>
                    </div>
                </div>
            `;
        }
    });

    if (formsHTML === '') {
        formsHTML = '<div class="text-center text-gray-500 py-8">No containers available for vehicle assignment. Please ensure containers have:<br><br>• Container number<br>• From location<br>• To location<br>• Not already assigned<br>• No trip created<br><br>Update container details first or assign vehicles to remaining containers.</div>';
    }

    assignmentSection.innerHTML = formsHTML;
    setupSearchInputs();
}

    // Handle vehicle type change
    function handleVehicleTypeChange(selectElement, containerId) {
        const ownedSection = document.getElementById(`owned-section-${containerId}`);
        const vendorSection = document.getElementById(`vendor-section-${containerId}`);
        const helpText = document.getElementById(`help-text-${containerId}`);
        
        // Hide both sections first
        if (ownedSection) ownedSection.style.display = 'none';
        if (vendorSection) vendorSection.style.display = 'none';
        
        if (selectElement.value === 'owned') {
            if (ownedSection) ownedSection.style.display = 'block';
            if (helpText) helpText.textContent = 'Search and select an owned vehicle from the dropdown.';
        } else if (selectElement.value === 'vendor') {
            if (vendorSection) vendorSection.style.display = 'block';
            if (helpText) helpText.textContent = 'Select a vendor and enter vehicle details. You can search existing vendor vehicles or add a new one.';
        } else {
            if (helpText) helpText.textContent = 'Please select a vehicle type to continue.';
        }
    }

    // Handle vendor selection
    function handleVendorSelection(vendorInput, containerId) {
        const vehicleNumberInput = document.getElementById(`vehicle_number_${containerId}`);
        const rateInput = document.getElementById(`vendor_rate_${containerId}`);
        const helpText = document.getElementById(`help-text-${containerId}`);
        
        // Check if vendor is selected
        const form = vendorInput.closest('.assignment-form');
        const vendorIdInput = form.querySelector('input[name*="[vendor_id]"]');
        const vendorId = vendorIdInput ? vendorIdInput.value : '';
        
        if (vendorId) {
            // Enable vehicle number and rate fields
            if (vehicleNumberInput) {
                vehicleNumberInput.disabled = false;
                vehicleNumberInput.placeholder = "Enter or search vehicle number...";
            }
            if (rateInput) {
                rateInput.disabled = false;
            }
            if (helpText) {
                helpText.textContent = 'Vendor selected. Now enter vehicle number and rate.';
            }
        } else {
            // Disable fields if no vendor selected
            if (vehicleNumberInput) {
                vehicleNumberInput.disabled = true;
                vehicleNumberInput.value = '';
                vehicleNumberInput.placeholder = "Select vendor first...";
            }
            if (rateInput) {
                rateInput.disabled = true;
                rateInput.value = '';
            }
            if (helpText) {
                helpText.textContent = 'Select a vendor first to enable vehicle and rate fields.';
            }
        }
    }

    // Search vendor vehicles
    function searchVendorVehicles(input, hiddenFieldName) {
        const query = input.value.trim();
        if (query.length < 2) {
            hideSearchDropdown(input);
            return;
        }
        
        // Get the vendor ID from the same form
        const form = input.closest('.assignment-form');
        const vendorIdInput = form.querySelector('input[name*="[vendor_id]"]');
        const vendorId = vendorIdInput ? vendorIdInput.value : '';
        
        if (!vendorId) {
            hideSearchDropdown(input);
            return;
        }
        
        clearTimeout(searchTimeouts.vendor_vehicles);
        
        searchTimeouts.vendor_vehicles = setTimeout(() => {
            fetch(`?ajax=search_vendor_vehicles&query=${encodeURIComponent(query)}&vendor_id=${vendorId}`)
                .then(response => response.json())
                .then(data => {
                    showSearchResults(input, data, hiddenFieldName, 'vendor_vehicle');
                })
                .catch(error => {
                    console.error('Search error:', error);
                    showSearchResults(input, [], hiddenFieldName, 'vendor_vehicle');
                });
        }, 300);
    }

    // FIXED: Search functions with proper error handling
    function searchLocations(input, hiddenFieldName) {
        const query = input.value.trim();
        if (query.length < 2) {
            hideSearchDropdown(input);
            return;
        }
        
        clearTimeout(searchTimeouts.locations);
        
        searchTimeouts.locations = setTimeout(() => {
            fetch(`?ajax=search_locations&query=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    showSearchResults(input, data, hiddenFieldName, 'location');
                })
                .catch(error => {
                    console.error('Search error:', error);
                    showSearchResults(input, [], hiddenFieldName, 'location');
                });
        }, 300);
    }

    function searchVehicles(input, hiddenFieldName) {
        const query = input.value.trim();
        if (query.length < 2) {
            hideSearchDropdown(input);
            return;
        }
        
        clearTimeout(searchTimeouts.vehicles);
        
        searchTimeouts.vehicles = setTimeout(() => {
            fetch(`?ajax=search_owned_vehicles&query=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    showSearchResults(input, data, hiddenFieldName, 'vehicle');
                })
                .catch(error => {
                    console.error('Search error:', error);
                    showSearchResults(input, [], hiddenFieldName, 'vehicle');
                });
        }, 300);
    }

    function searchVendors(input, hiddenFieldName) {
        const query = input.value.trim();
        if (query.length < 2) {
            hideSearchDropdown(input);
            return;
        }
        
        clearTimeout(searchTimeouts.vendors);
        
        searchTimeouts.vendors = setTimeout(() => {
            fetch(`?ajax=search_vendors&query=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    showSearchResults(input, data, hiddenFieldName, 'vendor');
                })
                .catch(error => {
                    console.error('Search error:', error);
                    showSearchResults(input, [], hiddenFieldName, 'vendor');
                });
        }, 300);
    }

    // FIXED: L1 supervisor search function with proper endpoint
    function searchL1Supervisors(input, hiddenFieldName) {
        const query = input.value.trim();
        if (query.length < 2) {
            hideSearchDropdown(input);
            return;
        }
        
        clearTimeout(searchTimeouts.l1_supervisors);
        
        searchTimeouts.l1_supervisors = setTimeout(() => {
            const searchUrl = window.location.pathname + '?ajax=search_l1_supervisors&query=' + encodeURIComponent(query);
            
            fetch(searchUrl)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    showSearchResults(input, data, hiddenFieldName, 'l1_supervisor');
                })
                .catch(error => {
                    console.error('Search error:', error);
                    showSearchResults(input, [], hiddenFieldName, 'l1_supervisor');
                });
        }, 300);
    }

    // Show search results
function showSearchResults(input, data, hiddenFieldName, type) {
    const dropdown = input.parentElement.querySelector('.search-dropdown');
    
    if (data.error) {
        dropdown.innerHTML = `<div class="search-dropdown-item text-red-500">Error: ${data.error}</div>`;
    } else if (data.length === 0) {
        dropdown.innerHTML = '<div class="search-dropdown-item text-gray-500">No results found</div>';
    } else {
        let html = '';
        data.forEach(item => {
            let displayText = '';
            let value = '';
            
            switch (type) {
                case 'location':
                    displayText = item.display_name; // e.g., "Main Warehouse (LOCATION)" or "Port Yard (YARD)"
                    value = item.value; // e.g., "location|123" or "yard|456"
                    break;
                case 'vehicle':
                    // Handle both single make_model and separate make/model
                    let vehicleDetails = item.vehicle_number;
                    if (item.make && item.model) {
                        vehicleDetails += ` - ${item.make} ${item.model}`;
                    } else if (item.make) {
                        vehicleDetails += ` - ${item.make}`;
                    }
                    
                    if (item.driver_name && item.driver_name !== 'No Driver') {
                        vehicleDetails += ` (${item.driver_name})`;
                    }
                    vehicleDetails += ` [${item.vehicle_type.toUpperCase()}]`;
                    
                    if (item.status && item.status !== 'unknown') {
                        vehicleDetails += ` - ${item.status.toUpperCase()}`;
                    }
                    
                    displayText = vehicleDetails;
                    value = item.id;
                    break;
                case 'vendor':
                    displayText = `${item.company_name} (${item.vendor_code}) - ${item.contact_person}`;
                    value = item.id;
                    break;
                case 'l1_supervisor':
                    displayText = `${item.full_name} (${item.phone_number || item.mobile})`;
                    value = item.phone_number || item.mobile;
                    break;
                case 'vendor_vehicle':
                    displayText = `${item.vehicle_number} - ${item.make || ''} ${item.model || ''} (${item.driver_name || 'No Driver'})`;
                    value = item.id;
                    break;
            }
            
            html += `<div class="search-dropdown-item" onclick="selectSearchResult('${value}', '${displayText.replace(/'/g, "\\'")}', '${input.id || 'temp_' + Math.random()}', '${hiddenFieldName}')">${displayText}</div>`;
        });
        dropdown.innerHTML = html;
    }
    
    dropdown.style.display = 'block';
}

// Add this debug function to your JavaScript
function debugContainerSubmission() {
    const form = document.getElementById('container-update-form');
    if (!form) {
        console.log('DEBUG: Form not found');
        return;
    }
    
    const formData = new FormData(form);
    
    console.log('=== CONTAINER FORM DEBUG ===');
    console.log('Booking ID:', formData.get('booking_id'));
    console.log('Total Containers:', formData.get('total_containers'));
    
    // Log all container data
    for (let [key, value] of formData.entries()) {
        if (key.startsWith('containers[')) {
            console.log(`${key}: ${value}`);
        }
    }
    
    // Count filled containers
    let filledContainers = 0;
    const containerKeys = new Set();
    
    for (let [key, value] of formData.entries()) {
        if (key.includes('[number1]') && value.trim() !== '') {
            filledContainers++;
            console.log(`Filled container found: ${key} = ${value}`);
        }
        
        // Extract container keys
        const match = key.match(/containers\[([^\]]+)\]/);
        if (match) {
            containerKeys.add(match[1]);
        }
    }
    
    console.log('Total container keys found:', containerKeys.size);
    console.log('Container keys:', Array.from(containerKeys));
    console.log('Filled containers count:', filledContainers);
    console.log('Expected total:', formData.get('total_containers'));
    console.log('=== END DEBUG ===');
}

// Modified submitContainerForm with debugging
function submitContainerForm() {
    const form = document.getElementById('container-update-form');
    if (!form) {
        showNotification('Form not found', 'error');
        return;
    }
    
    // Run debug before submission
    debugContainerSubmission();
    
    showLoading('Updating container details...');
    const formData = new FormData(form);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text(); // Get as text first to see raw response
    })
    .then(responseText => {
        console.log('Raw response:', responseText);
        
        // Try to parse as JSON
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error('Response is not valid JSON:', e);
            throw new Error('Invalid response format');
        }
        
        hideLoading();
        if (data.success) {
            showNotification(data.message, 'success');
            closeModal('container-update-modal');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(data.error || 'Error updating container details', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Submission error:', error);
        showNotification('Error submitting form: ' + error.message, 'error');
    });
}
    // Select search result
    function selectSearchResult(value, displayText, inputId, hiddenFieldName) {
        // Find the input either by ID or by searching for inputs that call the search function
        let input = document.getElementById(inputId);
        if (!input) {
            // Fallback: find input by searching for the onkeyup attribute
            const inputs = document.querySelectorAll('input[type="text"]');
            for (let inp of inputs) {
                if (inp.getAttribute('onkeyup') && inp.getAttribute('onkeyup').includes(hiddenFieldName)) {
                    input = inp;
                    break;
                }
            }
        }
        
        if (input) {
            const hiddenField = input.parentElement.querySelector(`input[name="${hiddenFieldName}"]`);
            
            if (hiddenField) {
                input.value = displayText;
                hiddenField.value = value;
                hideSearchDropdown(input);
                
                // Add visual feedback
                input.classList.add('border-green-500');
                setTimeout(() => {
                    input.classList.remove('border-green-500');
                }, 2000);
                
                // If this is a vendor selection, trigger the vendor selection handler
                if (hiddenFieldName.includes('vendor_id')) {
                    const containerId = hiddenFieldName.match(/assignments\[(\d+)\]/);
                    if (containerId) {
                        handleVendorSelection(input, containerId[1]);
                    }
                }
            }
        }
    }

    // Hide search dropdown
    function hideSearchDropdown(input) {
        const dropdown = input.parentElement.querySelector('.search-dropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
        }
    }

    // Setup search inputs
    function setupSearchInputs() {
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.relative')) {
                document.querySelectorAll('.search-dropdown').forEach(dropdown => {
                    dropdown.style.display = 'none';
                });
            }
        });
    }

    // File upload functions
    function triggerFileUpload(inputId) {
        const input = document.getElementById(inputId);
        if (input) {
            input.click();
        }
    }

    function handleFileSelect(input) {
        const file = input.files[0];
        if (file) {
            const preview = input.parentElement.querySelector('.file-preview');
            const reader = new FileReader();
            
            reader.onload = function(e) {
                preview.innerHTML = `
                    <img src="${e.target.result}" class="w-20 h-20 object-cover rounded-lg border border-gray-300">
                    <p class="text-xs text-green-600 mt-1">${file.name}</p>
                `;
                preview.style.display = 'block';
            };
            
            reader.readAsDataURL(file);
            
            input.parentElement.classList.add('border-green-500', 'bg-green-50');
        }
    }

    // Drag and drop functionality
    function enableDragAndDrop() {
        document.addEventListener('dragover', function(e) {
            e.preventDefault();
            const uploadArea = e.target.closest('.file-upload-area');
            if (uploadArea) {
                uploadArea.classList.add('dragover');
            }
        });
        
        document.addEventListener('dragleave', function(e) {
            const uploadArea = e.target.closest('.file-upload-area');
            if (uploadArea) {
                uploadArea.classList.remove('dragover');
            }
        });
        
        document.addEventListener('drop', function(e) {
            e.preventDefault();
            const uploadArea = e.target.closest('.file-upload-area');
            if (uploadArea) {
                uploadArea.classList.remove('dragover');
                const input = uploadArea.querySelector('input[type="file"]');
                if (input && e.dataTransfer.files.length > 0) {
                    input.files = e.dataTransfer.files;
                    handleFileSelect(input);
                }
            }
        });
    }

    // View booking details
    function viewBookingDetails(bookingId) {
        const content = document.getElementById('booking-details-content');
        if (content) {
            content.innerHTML = `
                <div class="text-center py-8">
                    <i class="fas fa-info-circle text-3xl text-gray-400 mb-3"></i>
                    <p class="text-gray-600">Detailed booking information for ID: ${bookingId}</p>
                    <p class="text-sm text-gray-500 mt-2">This feature will be implemented to show complete booking history and details.</p>
                </div>
            `;
        }
        openModal('bookingDetailsModal');
    }

    // Utility functions
    function showLoading(message = 'Loading...') {
        // Remove existing loading overlay
        const existingLoading = document.getElementById('loading-overlay');
        if (existingLoading) {
            existingLoading.remove();
        }
        
        const loading = document.createElement('div');
        loading.id = 'loading-overlay';
        loading.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center';
        loading.innerHTML = `
            <div class="bg-white rounded-lg p-6 flex items-center space-x-3">
                <i class="fas fa-spinner fa-spin text-teal-600 text-xl"></i>
                <span class="text-gray-700">${message}</span>
            </div>
        `;
        document.body.appendChild(loading);
    }

    function hideLoading() {
        const loading = document.getElementById('loading-overlay');
        if (loading) {
            loading.remove();
        }
    }

    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        const bgColor = type === 'success' ? 'bg-green-500' : 
                       type === 'error' ? 'bg-red-500' : 
                       type === 'warning' ? 'bg-yellow-500' : 'bg-blue-500';
        
        notification.className = `fixed top-4 right-4 ${bgColor} text-white px-6 py-3 rounded-lg shadow-lg z-50 animate-fade-in`;
        notification.innerHTML = `
            <div class="flex items-center">
                <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'times' : 'info'}-circle mr-2"></i>
                ${message}
            </div>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 5000);
    }

    function refreshPage() {
        showLoading('Refreshing...');
        location.reload();
    }

    // Initialize event listeners
    function initializeEventListeners() {
        // Handle form submissions with loading states
        document.addEventListener('submit', function(e) {
            const submitBtn = e.target.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                submitBtn.disabled = true;
                
                // Reset button after 10 seconds (fallback)
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 10000);
            }
        });

        // Handle modal close on outside click
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                const modalId = e.target.id;
                closeModal(modalId);
            }
        });

        // Handle escape key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const openModals = document.querySelectorAll('.modal[style*="block"]');
                openModals.forEach(modal => {
                    closeModal(modal.id);
                });
            }
        });
    }

    // Initialize modals - ensure they start hidden
    function initializeModals() {
        const modals = [
            'l1ContactModal', 
            'container-update-modal', 
            'vehicleAssignmentModal', 
            'bookingDetailsModal'
        ];
        
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                modal.classList.add('hidden');
            }
        });
    }

    // Auto-refresh functionality (reduced frequency and with user awareness)
    function setupAutoRefresh() {
        setInterval(function() {
            const modalsOpen = ['l1ContactModal', 'container-update-modal', 'vehicleAssignmentModal', 'bookingDetailsModal']
                .some(id => {
                    const modal = document.getElementById(id);
                    return modal && modal.style.display === 'block';
                });
            
            if (!modalsOpen) {
                const indicator = document.createElement('div');
                indicator.className = 'fixed bottom-4 right-4 bg-blue-500 text-white px-3 py-2 rounded-lg text-sm';
                indicator.innerHTML = '<i class="fas fa-sync-alt mr-2"></i>Checking for updates...';
                document.body.appendChild(indicator);
                
                setTimeout(() => {
                    indicator.remove();
                    // Only refresh if no user interaction in the last few seconds
                    if (document.hasFocus()) {
                        location.reload();
                    }
                }, 2000);
            }
        }, 60000); // Check every 60 seconds
    }

    // Initialize everything when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Initializing page...');
        initializeModals();
        enableDragAndDrop();
        setupSearchInputs();
        initializeEventListeners();
        setupAutoRefresh();
        console.log('Page initialization complete');
    });

    // Error handling for uncaught errors
    window.addEventListener('error', function(e) {
        console.error('JavaScript error:', e.error);
        showNotification('An unexpected error occurred. Please refresh the page.', 'error');
    });

    // Handle unhandled promise rejections
    window.addEventListener('unhandledrejection', function(e) {
        console.error('Unhandled promise rejection:', e.reason);
        showNotification('An error occurred while processing your request.', 'error');
    });
</script>
</body>
</html>