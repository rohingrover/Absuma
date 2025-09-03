<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Handle AJAX requests for vendor vehicles
if (isset($_GET['vendor_id'])) {
    header('Content-Type: application/json');
    
    try {
        $vendorId = (int)$_GET['vendor_id'];
        
        // Validate vendor exists
        $vendorCheck = $pdo->prepare("
            SELECT id, company_name, status 
            FROM vendors 
            WHERE id = ? AND deleted_at IS NULL
        ");
        $vendorCheck->execute([$vendorId]);
        $vendor = $vendorCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$vendor) {
            throw new Exception('Vendor not found');
        }
        
        // Get vendor vehicles with trip statistics
        $stmt = $pdo->prepare("
            SELECT 
                vv.id,
                vv.vendor_id,
                vv.vehicle_number,
                vv.make,
                vv.model,
                COALESCE(CONCAT(vv.make, ' ', vv.model), 
                         COALESCE(vv.make, vv.model, 'Unknown')) as make_model,
                vv.driver_name,
                vv.driver_license,
                vv.status,
                vv.created_at,
                vv.updated_at,
                
                -- Trip statistics
                COUNT(DISTINCT t.id) as total_trips,
                COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN t.id END) as completed_trips,
                COUNT(DISTINCT CASE WHEN t.status IN ('pending', 'in_progress') THEN t.id END) as active_trips,
                MAX(t.trip_date) as last_trip_date,
                
                -- Revenue calculation (last 30 days)
                COALESCE(SUM(CASE 
                    WHEN t.trip_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                    AND t.status = 'completed' 
                    AND t.is_vendor_vehicle = 1 
                    THEN t.vendor_rate 
                END), 0) as revenue_30_days
                
            FROM vendor_vehicles vv
            LEFT JOIN trips t ON (
                t.vehicle_id = vv.id AND t.is_vendor_vehicle = 1
            )
            WHERE vv.vendor_id = ? AND vv.deleted_at IS NULL
            GROUP BY vv.id
            ORDER BY 
                CASE vv.status 
                    WHEN 'active' THEN 1
                    WHEN 'maintenance' THEN 2
                    WHEN 'inactive' THEN 3
                    ELSE 4
                END,
                vv.vehicle_number ASC
        ");
        
        $stmt->execute([$vendorId]);
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process vehicle data
        foreach ($vehicles as &$vehicle) {
            // Format dates
            $vehicle['created_at_formatted'] = date('d M Y', strtotime($vehicle['created_at']));
            $vehicle['last_trip_formatted'] = $vehicle['last_trip_date'] ? 
                date('d M Y', strtotime($vehicle['last_trip_date'])) : 'No trips yet';
            
            // Calculate days since last trip
            $vehicle['days_since_last_trip'] = $vehicle['last_trip_date'] ? 
                (int)((time() - strtotime($vehicle['last_trip_date'])) / (24 * 60 * 60)) : null;
            
            // Determine operational status
            if ($vehicle['status'] === 'inactive') {
                $vehicle['operational_status'] = 'inactive';
            } elseif ($vehicle['status'] === 'maintenance') {
                $vehicle['operational_status'] = 'maintenance';
            } elseif ($vehicle['active_trips'] > 0) {
                $vehicle['operational_status'] = 'on_trip';
            } elseif ($vehicle['status'] === 'active') {
                $vehicle['operational_status'] = 'available';
            } else {
                $vehicle['operational_status'] = 'unknown';
            }
            
            // Format revenue
            $vehicle['revenue_30_days_formatted'] = '₹' . number_format($vehicle['revenue_30_days'], 2);
            
            // Calculate utilization rate
            $vehicle['utilization_rate'] = $vehicle['total_trips'] > 0 ? 
                round(($vehicle['completed_trips'] / $vehicle['total_trips']) * 100) : 0;
        }
        
        // Calculate summary statistics
        $summary = [
            'total_vehicles' => count($vehicles),
            'active_vehicles' => count(array_filter($vehicles, fn($v) => $v['operational_status'] === 'available')),
            'on_trip_vehicles' => count(array_filter($vehicles, fn($v) => $v['operational_status'] === 'on_trip')),
            'maintenance_vehicles' => count(array_filter($vehicles, fn($v) => $v['operational_status'] === 'maintenance')),
            'inactive_vehicles' => count(array_filter($vehicles, fn($v) => $v['operational_status'] === 'inactive')),
            'total_revenue_30_days' => array_sum(array_column($vehicles, 'revenue_30_days')),
            'vendor_info' => [
                'name' => $vendor['company_name'],
                'status' => $vendor['status']
            ]
        ];
        
        echo json_encode([
            'success' => true,
            'vehicles' => $vehicles,
            'summary' => $summary
        ]);
        
    } catch (Exception $e) {
        error_log("Error fetching vendor vehicles: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'vehicles' => [],
            'summary' => null
        ]);
    }
    exit;
}

// If no vendor_id provided, return error
echo json_encode([
    'success' => false,
    'error' => 'Vendor ID not provided',
    'vehicles' => [],
    'summary' => null
]);
?>