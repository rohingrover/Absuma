<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

try {
    $clientId = $_GET['client_id'] ?? null;
    $format = $_GET['format'] ?? 'csv';
    
    if (!$clientId || !is_numeric($clientId)) {
        throw new Exception('Invalid client ID');
    }
    
    // Fetch client details
    $clientStmt = $pdo->prepare("SELECT client_name, client_code FROM clients WHERE id = ? AND deleted_at IS NULL");
    $clientStmt->execute([$clientId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        throw new Exception('Client not found');
    }
    
    // Fetch rates with location names
    $ratesStmt = $pdo->prepare("
        SELECT 
            cr.container_size,
            cr.movement_type,
            cr.container_type,
            l1.location as from_location,
            l2.location as to_location,
            cr.rate,
            cr.effective_from,
            cr.effective_to,
            cr.remarks,
            cr.created_at,
            cr.updated_at
        FROM client_rates cr
        LEFT JOIN location l1 ON cr.from_location_id = l1.id
        LEFT JOIN location l2 ON cr.to_location_id = l2.id
        WHERE cr.client_id = ? AND cr.deleted_at IS NULL
        ORDER BY cr.container_size, cr.movement_type, l1.location
    ");
    $ratesStmt->execute([$clientId]);
    $rates = $ratesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        // Set CSV headers
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $client['client_name'] . '_rates_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, [
            'Client Name',
            'Client Code', 
            'Container Size',
            'Movement Type',
            'Container Type',
            'From Location',
            'To Location',
            'Rate (₹)',
            'Effective From',
            'Effective To',
            'Remarks',
            'Created Date'
        ]);
        
        // CSV data
        foreach ($rates as $rate) {
            fputcsv($output, [
                $client['client_name'],
                $client['client_code'],
                $rate['container_size'],
                ucfirst($rate['movement_type']),
                ucfirst($rate['container_type']),
                $rate['from_location'] ?: 'Local',
                $rate['to_location'] ?: 'Local',
                '₹' . number_format($rate['rate'], 2),
                $rate['effective_from'] ? date('d-M-Y', strtotime($rate['effective_from'])) : 'No Start Date',
                $rate['effective_to'] ? date('d-M-Y', strtotime($rate['effective_to'])) : 'No End Date',
                $rate['remarks'] ?: 'No remarks',
                date('d-M-Y', strtotime($rate['created_at']))
            ]);
        }
        
        fclose($output);
        exit;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo 'Export failed: ' . $e->getMessage();
}
?>