<?php
header('Content-Type: application/json');
session_start();
require 'auth_check.php';
require 'db_connection.php';

try {
    $search = $_GET['search'] ?? '';
    $vehicles = [];
    
    if (strlen($search) >= 2) {
        // Check if search is numeric (likely last 4 digits)
        $isNumericSearch = is_numeric($search);
        
        if ($isNumericSearch && strlen($search) <= 4) {
            // Priority search for last 4 digits
            $stmt = $pdo->prepare("
                SELECT 
                    v.id, 
                    v.vehicle_number, 
                    COALESCE(NULLIF(v.make_model, ''), 'Unknown Make/Model') as make_model,
                    COALESCE(v.driver_name, 'No Driver') as driver_name,
                    v.current_status,
                    v.vehicle_type,
                    'owned' as ownership_type
                FROM vehicles v 
                WHERE v.deleted_at IS NULL 
                AND v.current_status IN ('available', 'active')
                AND RIGHT(REPLACE(REPLACE(v.vehicle_number, ' ', ''), '-', ''), 4) LIKE ?
                ORDER BY v.vehicle_number ASC
                LIMIT 15
            ");
            $searchParam = "%$search%";
            $stmt->execute([$searchParam]);
        } else {
            // General search for text or full vehicle numbers
            $stmt = $pdo->prepare("
                SELECT 
                    v.id, 
                    v.vehicle_number, 
                    COALESCE(NULLIF(v.make_model, ''), 'Unknown Make/Model') as make_model,
                    COALESCE(v.driver_name, 'No Driver') as driver_name,
                    v.current_status,
                    v.vehicle_type,
                    'owned' as ownership_type
                FROM vehicles v 
                WHERE v.deleted_at IS NULL 
                AND v.current_status IN ('available', 'active')
                AND (v.vehicle_number LIKE ? OR v.make_model LIKE ? OR v.driver_name LIKE ?
                     OR RIGHT(REPLACE(REPLACE(v.vehicle_number, ' ', ''), '-', ''), 4) LIKE ?)
                ORDER BY 
                    CASE 
                        WHEN RIGHT(REPLACE(REPLACE(v.vehicle_number, ' ', ''), '-', ''), 4) LIKE ? THEN 1
                        WHEN v.vehicle_number LIKE ? THEN 2
                        ELSE 3
                    END,
                    v.vehicle_number ASC
                LIMIT 15
            ");
            $searchParam = "%$search%";
            $stmt->execute([$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
        }
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format results for consistent API response
        foreach ($results as $vehicle) {
            $last4 = substr(preg_replace('/[\s\-]/', '', $vehicle['vehicle_number']), -4);
            $vehicles[] = [
                'id' => $vehicle['id'] . '|owned',
                'vehicle_id' => $vehicle['id'],
                'vehicle_number' => $vehicle['vehicle_number'],
                'last4' => $last4,
                'make_model' => $vehicle['make_model'],
                'driver_name' => $vehicle['driver_name'],
                'status' => $vehicle['current_status'],
                'vehicle_type' => $vehicle['vehicle_type'],
                'ownership_type' => 'owned',
                'display_text' => $vehicle['vehicle_number'],
                'details' => $vehicle['make_model'] . ' - ' . $vehicle['driver_name'],
                'type_label' => 'Owned Vehicle',
                'is_vendor' => false,
                'vendor_name' => null
            ];
        }
    }
    
    echo json_encode($vehicles);
    
} catch (Exception $e) {
    error_log("Error in get_owned_vehicles.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'vehicles' => []
    ]);
}