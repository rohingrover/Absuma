<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

header('Content-Type: application/json');

try {
    $clientId = $_GET['client_id'] ?? null;
    
    if (!$clientId || !is_numeric($clientId)) {
        throw new Exception('Invalid client ID');
    }
    
    // Get client rates
    $stmt = $pdo->prepare("
        SELECT 
            id,
            container_size,
            movement_type,
            container_type,
            import_export,
            rate,
            remarks,
            effective_from,
            effective_to,
            is_active,
            created_at
        FROM client_rates 
        WHERE client_id = ? AND deleted_at IS NULL 
        ORDER BY container_size, movement_type, container_type, import_export
    ");
    $stmt->execute([$clientId]);
    $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($rates);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?>