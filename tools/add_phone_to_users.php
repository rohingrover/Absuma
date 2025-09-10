<?php
require __DIR__ . '/../db_connection.php';

try {
    // Check if phone_number column already exists
    $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone_number'");
    
    if ($checkColumn->rowCount() == 0) {
        // Add phone_number column to users table
        $sql = "ALTER TABLE users ADD COLUMN phone_number VARCHAR(15) NULL AFTER email";
        $pdo->exec($sql);
        echo "✅ Successfully added phone_number column to users table\n";
    } else {
        echo "ℹ️ phone_number column already exists in users table\n";
    }
    
    // Show current users table structure
    echo "\n📋 Current users table structure:\n";
    $columns = $pdo->query("SHOW COLUMNS FROM users");
    while ($column = $columns->fetch()) {
        echo "- {$column['Field']} ({$column['Type']}) - {$column['Null']} - {$column['Key']}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
