<?php
// Migration: Ensure 20ft containers store only one number
// - Make booking_containers.container_number_2 nullable
// - Null out container_number_2 wherever container_type is 20ft

require __DIR__ . '/../db_connection.php';

header('Content-Type: text/plain');

try {
    $pdo->beginTransaction();

    // Ensure table exists
    $exists = $pdo->query("SHOW TABLES LIKE 'booking_containers'")->fetch();
    if (!$exists) {
        echo "booking_containers table not found. Nothing to migrate.\n";
        $pdo->rollBack();
        exit(0);
    }

    // Make column container_number_2 NULLable if not already
    $colInfo = $pdo->query("SHOW COLUMNS FROM booking_containers LIKE 'container_number_2'")->fetch();
    if ($colInfo) {
        $isNullable = strtoupper($colInfo['Null'] ?? '') === 'YES';
        $type = $colInfo['Type'] ?? 'varchar(50)';
        if (!$isNullable) {
            // Preserve type length, convert to NULL
            $sql = "ALTER TABLE booking_containers MODIFY container_number_2 $type NULL";
            $pdo->exec($sql);
            echo "Altered container_number_2 to be NULLABLE.\n";
        } else {
            echo "container_number_2 already NULLABLE.\n";
        }
    } else {
        echo "container_number_2 column not found. Skipping NULLABLE alter.\n";
    }

    // Null out second numbers for 20ft
    $updated = $pdo->exec("UPDATE booking_containers SET container_number_2 = NULL WHERE container_type = '20ft' AND container_number_2 IS NOT NULL");
    echo "Rows updated (set number_2 NULL for 20ft): " . (int)$updated . "\n";

    $pdo->commit();
    echo "Migration completed successfully.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>


