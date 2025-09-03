<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Set response content type
header('Content-Type: application/json');

try {
    // Get client ID from request
    $clientId = $_GET['client_id'] ?? null;
    
    // Validate client ID
    if (!$clientId || !is_numeric($clientId)) {
        throw new Exception('Invalid client ID');
    }
    
    // Fetch client details
    $clientStmt = $pdo->prepare("
        SELECT id, client_code, client_name, contact_person, phone_number, 
               email_address, billing_address, billing_cycle_days, 
               pan_number, gst_number, status
        FROM clients 
        WHERE id = ? AND deleted_at IS NULL
    ");
    $clientStmt->execute([$clientId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        throw new Exception('Client not found');
    }
    
    // Fetch client rates with location names
    $ratesStmt = $pdo->prepare("
        SELECT 
            cr.*,
            l1.location as from_location,
            l2.location as to_location
        FROM client_rates cr
        LEFT JOIN location l1 ON cr.from_location_id = l1.id
        LEFT JOIN location l2 ON cr.to_location_id = l2.id
        WHERE cr.client_id = ? AND cr.deleted_at IS NULL
        ORDER BY 
            cr.container_size ASC,
            cr.movement_type ASC,
            l1.location ASC,
            l2.location ASC
    ");
    $ratesStmt->execute([$clientId]);
    $rates = $ratesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format rates for better display
    $formattedRates = array_map(function($rate) {
        return [
            'id' => $rate['id'],
            'container_size' => $rate['container_size'],
            'movement_type' => $rate['movement_type'],
            'container_type' => $rate['container_type'],
            'from_location' => $rate['from_location'],
            'to_location' => $rate['to_location'],
            'from_location_id' => $rate['from_location_id'],
            'to_location_id' => $rate['to_location_id'],
            'rate' => number_format($rate['rate'], 2, '.', ''),
            'effective_from' => $rate['effective_from'],
            'effective_to' => $rate['effective_to'],
            'remarks' => $rate['remarks'],
            'is_active' => $rate['is_active'],
            'created_at' => $rate['created_at'],
            'updated_at' => $rate['updated_at']
        ];
    }, $rates);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'client' => $client,
        'rates' => $formattedRates,
        'summary' => [
            'total_rates' => count($rates),
            'rates_20ft' => count(array_filter($rates, fn($r) => $r['container_size'] === '20ft')),
            'rates_40ft' => count(array_filter($rates, fn($r) => $r['container_size'] === '40ft')),
            'avg_rate' => count($rates) > 0 ? array_sum(array_column($rates, 'rate')) / count($rates) : 0,
            'min_rate' => count($rates) > 0 ? min(array_column($rates, 'rate')) : 0,
            'max_rate' => count($rates) > 0 ? max(array_column($rates, 'rate')) : 0
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>