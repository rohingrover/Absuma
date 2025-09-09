<?php
session_start();
require "auth_check.php";
require "db_connection.php";

header("Content-Type: application/json");

if (!isset($_GET["id"])) {
    echo json_encode(["success" => false, "message" => "Vehicle ID not provided"]);
    exit();
}

$vehicle_id = $_GET["id"];

try {
    // Get vehicle data
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vehicle) {
        echo json_encode(["success" => false, "message" => "Vehicle not found"]);
        exit();
    }
    
    // Get financing data
    $stmt = $pdo->prepare("SELECT * FROM vehicle_financing WHERE vehicle_id = ?");
    $stmt->execute([$vehicle_id]);
    $financing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        "success" => true,
        "vehicle" => $vehicle,
        "financing" => $financing
    ]);
    
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>