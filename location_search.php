<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

header('Content-Type: application/json');

try {
    $search = $_GET['search'] ?? '';
    $limit = $_GET['limit'] ?? 10;
    
    // Validate search term
    if (strlen($search) < 2) {
        echo json_encode([]);
        exit;
    }
    
    // Search locations
    $stmt = $pdo->prepare("
        SELECT id, location 
        FROM location 
        WHERE location LIKE ? 
        ORDER BY location ASC 
        LIMIT ?
    ");
    
    $searchTerm = '%' . $search . '%';
    $stmt->execute([$searchTerm, (int)$limit]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($locations);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>