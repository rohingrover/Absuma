<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// RBAC: allow manager1, admin, superadmin to view
if (!in_array($_SESSION['role'] ?? '', ['manager1', 'admin', 'superadmin'])) {
    header('Location: ../dashboard.php');
    exit();
}

$bookingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$bookingCode = isset($_GET['booking_id']) ? trim($_GET['booking_id']) : '';
if ($bookingId <= 0 && $bookingCode === '') {
    header('Location: manage.php');
    exit();
}

// Fetch booking with joins
$sql = "SELECT b.*, c.client_name, c.client_code, fl.location AS from_location, tl.location AS to_location, u.full_name AS created_by_name
        FROM bookings b
        LEFT JOIN clients c ON b.client_id = c.id
        LEFT JOIN location fl ON b.from_location_id = fl.id
        LEFT JOIN location tl ON b.to_location_id = tl.id
        LEFT JOIN users u ON b.created_by = u.id
        WHERE ";
if ($bookingId > 0) {
    $sql .= "b.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$bookingId]);
} else {
    $sql .= "b.booking_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$bookingCode]);
}
$booking = $stmt->fetch();
if (!$booking) {
    header('Location: manage.php');
    exit();
}

// Fetch containers if table exists
$containers = [];
$legacy_containers = [];
try {
    $existsStmt = $pdo->query("SHOW TABLES LIKE 'booking_containers'");
    if ($existsStmt->fetch()) {
        // Detect if per-container location columns exist
        $bcCols = [];
        try {
            $colsStmt = $pdo->query("SHOW COLUMNS FROM booking_containers");
            $bcCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (Exception $e) { $bcCols = []; }
        $hasPerContainerLoc = in_array('from_location_id', $bcCols, true) && in_array('to_location_id', $bcCols, true);

        if ($hasPerContainerLoc) {
            $c = $pdo->prepare("SELECT bc.*, fl.location AS from_location_name, tl.location AS to_location_name
                                 FROM booking_containers bc
                                 LEFT JOIN location fl ON bc.from_location_id = fl.id
                                 LEFT JOIN location tl ON bc.to_location_id = tl.id
                                 WHERE bc.booking_id = ?
                                 ORDER BY bc.container_sequence");
        } else {
            $c = $pdo->prepare("SELECT * FROM booking_containers WHERE booking_id = ? ORDER BY container_sequence");
        }
        $c->execute([$booking['id']]);
        $containers = $c->fetchAll();
    }
} catch (Exception $e) {}

// Fallback for legacy schemas: if no rows in booking_containers, try old columns on bookings
if (empty($containers)) {
    try {
        $colsStmt = $pdo->query("SHOW COLUMNS FROM bookings");
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        if (in_array('container_type', $cols, true) || in_array('container_number', $cols, true)) {
            if (!empty($booking['container_type']) || !empty($booking['container_number'])) {
                $legacy_containers[] = [
                    'container_sequence' => 1,
                    'container_type' => $booking['container_type'] ?? '',
                    'container_number_1' => $booking['container_number'] ?? '',
                    'container_number_2' => null,
                ];
            }
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Booking - Absuma Logistics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --absuma-red: #0d9488; /* teal-600 */
            --absuma-red-light: #f0fdfa; /* teal-50 */
            --absuma-red-dark: #0f766e; /* teal-700 */
        }
        
        .text-absuma-red { color: var(--absuma-red); }
        .bg-absuma-red { background-color: var(--absuma-red); }
        .border-absuma-red { border-color: var(--absuma-red); }
        .hover\:bg-absuma-red:hover { background-color: var(--absuma-red); }
        .hover\:text-absuma-red:hover { color: var(--absuma-red); }
        
        .gradient-bg {
            background: linear-gradient(135deg, #f0fdfa 0%, #e6fffa 100%);
            min-height: 100vh;
        }
        
        .shadow-soft {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
    </style>
</head>
<body class="gradient-bg overflow-x-hidden">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <?php include '../sidebar_navigation.php'; ?>

        <!-- Right content shell -->
        <div class="flex-1 flex flex-col">
            <main class="flex-1 p-6 overflow-auto">
                <div class="max-w-7xl mx-auto">
                    <div class="bg-white rounded-xl shadow-soft p-6 border-l-4 border-absuma-red">
                        <div class="flex items-center justify-between mb-4">
                            <h1 class="text-xl font-bold text-gray-800">
                                <i class="fas fa-eye text-absuma-red mr-2"></i>Booking Details
                            </h1>
                            <div class="flex gap-2">
                                <a href="edit.php?booking_id=<?= urlencode($booking['booking_id']) ?>" class="bg-absuma-red hover:bg-absuma-red-dark text-white px-3 py-1.5 rounded text-sm">Edit</a>
                                <a href="manage.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded text-sm">Back</a>
                            </div>
                        </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <div class="text-sm text-gray-500">Booking ID</div>
                        <div class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($booking['booking_id']) ?></div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Client</div>
                        <div class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($booking['client_name']) ?> <span class="text-gray-500 text-sm"><?= htmlspecialchars($booking['client_code']) ?></span></div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Containers</div>
                        <div class="text-lg font-semibold text-gray-900"><?= (int)$booking['no_of_containers'] ?></div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Status</div>
                        <div class="text-lg font-semibold text-gray-900"><?= ucfirst(str_replace('_',' ', $booking['status'])) ?></div>
                    </div>
                    <div class="md:col-span-2">
                        <div class="text-sm text-gray-500">Route</div>
                        <div class="text-lg font-semibold text-gray-900">
                            <?= $booking['from_location'] ? htmlspecialchars($booking['from_location']) : 'N/A' ?>
                            <i class="fas fa-arrow-right mx-2 text-gray-400"></i>
                            <?= $booking['to_location'] ? htmlspecialchars($booking['to_location']) : 'N/A' ?>
                        </div>
                    </div>
                </div>
                <div class="mt-6">
                    <h2 class="text-lg font-semibold mb-3">Containers</h2>
                    <?php if (empty($containers) && empty($legacy_containers)): ?>
                        <div class="text-sm text-gray-500">No container details</div>
                    <?php else: ?>
                        <div class="bg-gray-50 rounded-lg border overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="text-left px-4 py-2">#</th>
                                        <th class="text-left px-4 py-2">Size</th>
                                        <th class="text-left px-4 py-2">Number(s)</th>
                                        <th class="text-left px-4 py-2">From</th>
                                        <th class="text-left px-4 py-2">To</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (!empty($containers) ? $containers : $legacy_containers as $c): ?>
                                        <tr class="border-t">
                                            <td class="px-4 py-2"><?= (int)$c['container_sequence'] ?></td>
                                            <td class="px-4 py-2"><?= htmlspecialchars($c['container_type'] ?? '') ?></td>
                                            <td class="px-4 py-2">
                                                <?= htmlspecialchars($c['container_number_1'] ?? '') ?>
                                                <?php if (!empty($c['container_number_2'])): ?>, <?= htmlspecialchars($c['container_number_2'] ?? '') ?><?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2">
                                                <?php if (isset($c['from_location_name'])): ?>
                                                    <?= $c['from_location_name'] ? htmlspecialchars($c['from_location_name']) : '—' ?>
                                                <?php else: ?>
                                                    <?= $booking['from_location'] ? htmlspecialchars($booking['from_location']) : '—' ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2">
                                                <?php if (isset($c['to_location_name'])): ?>
                                                    <?= $c['to_location_name'] ? htmlspecialchars($c['to_location_name']) : '—' ?>
                                                <?php else: ?>
                                                    <?= $booking['to_location'] ? htmlspecialchars($booking['to_location']) : '—' ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    </div>
                    </div>
                </main>
        </div>
    </div>
</body>
</html>
