<?php
// Set timezone to Asia/Kolkata
date_default_timezone_set('Asia/Kolkata');

session_start();
require '../auth_check.php';
require '../db_connection.php';

// RBAC: allow manager1, admin, superadmin to generate PDF
if (!in_array($_SESSION['role'] ?? '', ['manager1', 'admin', 'superadmin'])) {
    header('Location: ../dashboard.php');
    exit();
}

$bookingId = isset($_GET['booking_id']) ? trim($_GET['booking_id']) : '';

if (!$bookingId) {
    die('Invalid booking ID');
}

// Fetch booking with joins
$sql = "SELECT b.*, c.client_name, c.client_code, c.contact_person as client_contact, c.phone_number as client_phone, c.email_address as client_email, c.billing_address as client_address, fl.location AS from_location, tl.location AS to_location, u.full_name AS created_by_name
        FROM bookings b
        LEFT JOIN clients c ON b.client_id = c.id
        LEFT JOIN location fl ON b.from_location_id = fl.id
        LEFT JOIN location tl ON b.to_location_id = tl.id
        LEFT JOIN users u ON b.created_by = u.id
        WHERE b.booking_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    die('Booking not found');
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

// Removed download action handling - users can now use browser's print dialog to save as PDF

// Check if all containers have the same route
$showMainRoute = true;
$allContainers = !empty($containers) ? $containers : $legacy_containers;
if (!empty($allContainers)) {
    $firstFrom = $allContainers[0]['from_location_name'] ?? $booking['from_location'];
    $firstTo = $allContainers[0]['to_location_name'] ?? $booking['to_location'];
    
    foreach ($allContainers as $c) {
        $containerFrom = $c['from_location_name'] ?? $booking['from_location'];
        $containerTo = $c['to_location_name'] ?? $booking['to_location'];
        
        if ($containerFrom !== $firstFrom || $containerTo !== $firstTo) {
            $showMainRoute = false;
            break;
        }
    }
}

// Calculate container counts
$count20ft = 0;
$count40ft = 0;
foreach ($allContainers as $c) {
    if (strpos($c['container_type'] ?? '', '20ft') !== false) {
        $count20ft++;
    }
    if (strpos($c['container_type'] ?? '', '40ft') !== false) {
        $count40ft++;
    }
}

// Fetch users with phone numbers for WhatsApp dropdown
$users = [];
try {
    $usersStmt = $pdo->prepare("SELECT id, full_name, phone_number FROM users WHERE status = 'active' AND phone_number IS NOT NULL AND phone_number != '' ORDER BY full_name");
    $usersStmt->execute();
    $users = $usersStmt->fetchAll();
} catch (Exception $e) {
    // If error, users array remains empty
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Receipt - <?= htmlspecialchars($booking['booking_id']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
            #whatsappModal { display: none !important; }
            .fixed { display: none !important; }
        }
        
        /* Ensure modal is properly positioned and visible when shown */
        #whatsappModal {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            z-index: 9999 !important;
            background: rgba(0, 0, 0, 0.5) !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 20px !important;
            box-sizing: border-box !important;
        }
        
        #whatsappModal.show {
            display: flex !important;
        }
        
        #whatsappModal .relative {
            position: relative !important;
            max-width: 500px !important;
            width: 100% !important;
            background: white !important;
            border-radius: 12px !important;
            padding: 24px !important;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
            max-height: 90vh !important;
            overflow-y: auto !important;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
            background: white;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: white;
        }
        
        .header {
            border-bottom: 3px solid #0d9488;
            padding-bottom: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            flex: 1;
        }
        
        .logo-placeholder {
            width: 60px;
            height: 60px;
            background-color: #0d9488;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
            border-radius: 5px;
        }
        
        .company-info h1 {
            font-size: 18px;
            color: #0d9488;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .company-info .tagline {
            font-size: 10px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .company-info .details {
            font-size: 9px;
            color: #666;
            line-height: 1.3;
        }
        
        .receipt-info {
            text-align: right;
            flex-shrink: 0;
        }
        
        .receipt-badge {
            background-color: #0d9488;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .reference-number {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .generated-date {
            font-size: 9px;
            color: #666;
        }
        
        .section {
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }
        
        .section-header {
            background-color: #f0fdfa;
            padding: 10px;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
            color: #0d9488;
            text-align: center;
            font-size: 14px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        
        .info-item {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            display: flex;
        }
        
        .info-item:nth-child(odd) {
            border-right: 1px solid #eee;
        }
        
        .info-label {
            font-weight: bold;
            min-width: 120px;
            color: #555;
        }
        
        .info-value {
            flex: 1;
        }
        
        .status-badge {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
        }
        
        .status-pending { background-color: #fef3c7; color: #92400e; }
        .status-confirmed { background-color: #dbeafe; color: #1e40af; }
        .status-in-progress { background-color: #e9d5ff; color: #6b21a8; }
        .status-completed { background-color: #d1fae5; color: #065f46; }
        .status-cancelled { background-color: #fee2e2; color: #dc2626; }
        
        .container-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .container-table th,
        .container-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        
        .container-table th {
            background-color: #f0fdfa;
            font-weight: bold;
            color: #555;
        }
        
        .container-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin: 15px 0;
        }
        
        .summary-item {
            text-align: center;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .summary-number {
            font-size: 18px;
            font-weight: bold;
            color: #0d9488;
        }
        
        .summary-label {
            font-size: 10px;
            color: #666;
            margin-top: 2px;
        }
        
        .terms-section {
            font-size: 10px;
            line-height: 1.5;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
        }
        
        .footer {
            border-top: 2px solid #0d9488;
            padding-top: 10px;
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #666;
        }
        
        .print-buttons {
            text-align: center;
            margin: 20px 0;
        }
        
        .btn {
            padding: 10px 20px;
            margin: 0 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background-color: #0d9488;
            color: white;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-success {
            background-color: #25d366;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        /* Enhanced input styling to match create booking page */
        .input-enhanced {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background-color: white;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        
        .input-enhanced:focus {
            outline: none;
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1), 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transform: scale(1.02);
        }
        
        /* Fix dropdown styling */
        select.input-enhanced {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 40px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Print/Download Buttons -->
        <div class="print-buttons no-print">
            <button onclick="printAndDownloadPDF()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print/Download PDF
            </button>
            <button type="button" onclick="shareToWhatsApp()" class="btn btn-success" id="whatsappBtn">
                <i class="fab fa-whatsapp"></i> Share to WhatsApp
            </button>
            <a href="view.php?id=<?= $booking['id'] ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Booking
            </a>
        </div>

        <!-- Header Section -->
        <div class="header">
            <div class="logo-section">
                <div class="logo-placeholder">
                    LOGO
                </div>
                <div class="company-info">
                    <h1>ABSUMA LOGISTICS INDIA PVT. LTD.</h1>
                    <div class="tagline">Transportation • Fumigation • FHAT • WPM</div>
                    <div class="details">
                        <strong>Regd. Office:</strong> Plot No. 123, Industrial Area, Phase-2, Chandigarh - 160002<br>
                        <strong>Phone:</strong> +91-172-4567890 | <strong>Email:</strong> info@absumalogistics.com<br>
                        <strong>GST:</strong> 04ABCDE1234F1Z5 | <strong>PAN:</strong> ABCDE1234F
                    </div>
                </div>
            </div>
            <div class="receipt-info">
                <div class="receipt-badge">BOOKING RECEIPT</div>
                <div class="reference-number"><?= htmlspecialchars($booking['booking_id']) ?></div>
                <div class="generated-date">Generated: <?= date('d-M-Y H:i', time()) ?></div>
            </div>
        </div>

        <!-- Booking Summary -->
        <div class="section">
            <div class="section-header">BOOKING SUMMARY</div>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-number"><?= (int)$booking['no_of_containers'] ?></div>
                    <div class="summary-label">Total Containers</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number"><?= $count20ft ?></div>
                    <div class="summary-label">20ft Containers</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number"><?= $count40ft ?></div>
                    <div class="summary-label">40ft Containers</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number"><?= max(1, floor((time() - strtotime($booking['created_at'])) / 86400)) ?></div>
                    <div class="summary-label">Days Old</div>
                </div>
            </div>
        </div>

        <!-- Booking Information -->
        <div class="section">
            <div class="section-header">BOOKING INFORMATION</div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Booking ID:</span>
                    <span class="info-value"><?= htmlspecialchars($booking['booking_id']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Status:</span>
                    <span class="info-value">
                        <span class="status-badge status-<?= $booking['status'] ?>">
                            <?= strtoupper(str_replace('_', ' ', $booking['status'])) ?>
                        </span>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Created Date:</span>
                    <span class="info-value"><?= date('d-M-Y', strtotime($booking['created_at'])) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Created Time:</span>
                    <span class="info-value"><?= date('g:i A', strtotime($booking['created_at'])) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Created By:</span>
                    <span class="info-value"><?= htmlspecialchars($booking['created_by_name'] ?? 'Unknown') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Last Updated:</span>
                    <span class="info-value"><?= isset($booking['updated_at']) && $booking['updated_at'] ? date('d-M-Y g:i A', strtotime($booking['updated_at'])) : 'Never' ?></span>
                </div>
            </div>
            <?php if ($showMainRoute): ?>
            <div class="info-item" style="grid-column: 1 / -1;">
                <span class="info-label">Route:</span>
                <span class="info-value">
                    <strong><?= $booking['from_location'] ? htmlspecialchars($booking['from_location']) : 'N/A' ?></strong> 
                    → 
                    <strong><?= $booking['to_location'] ? htmlspecialchars($booking['to_location']) : 'N/A' ?></strong>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Client Information -->
        <div class="section">
            <div class="section-header">CLIENT INFORMATION</div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Client Name:</span>
                    <span class="info-value"><?= htmlspecialchars($booking['client_name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Client Code:</span>
                    <span class="info-value"><?= htmlspecialchars($booking['client_code']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Contact Person:</span>
                    <span class="info-value"><?= htmlspecialchars($booking['client_contact'] ?: 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Phone:</span>
                    <span class="info-value"><?= htmlspecialchars($booking['client_phone'] ?: 'N/A') ?></span>
                </div>
            </div>
            <div class="info-item" style="grid-column: 1 / -1;">
                <span class="info-label">Email:</span>
                <span class="info-value"><?= htmlspecialchars($booking['client_email'] ?: 'N/A') ?></span>
            </div>
            <?php if ($booking['client_address']): ?>
            <div class="info-item" style="grid-column: 1 / -1;">
                <span class="info-label">Address:</span>
                <span class="info-value"><?= htmlspecialchars($booking['client_address']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Container Information -->
        <div class="section">
            <div class="section-header">CONTAINER DETAILS</div>
            <?php if (empty($allContainers)): ?>
                <div style="text-align: center; padding: 20px; color: #666;">
                    No container details available
                </div>
            <?php else: ?>
                <table class="container-table">
                    <thead>
                        <tr>
                            <th style="width: 10%;">#</th>
                            <th style="width: 20%;">Size</th>
                            <th style="width: 35%;">Container Number(s)</th>
                            <th style="width: 17.5%;">From Location</th>
                            <th style="width: 17.5%;">To Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allContainers as $c): ?>
                            <tr>
                                <td><?= (int)$c['container_sequence'] ?></td>
                                <td>
                                    <?php
                                    $size = $c['container_type'] ?? '';
                                    $sizeColor = strpos($size, '20ft') !== false ? 'background-color: #dbeafe; color: #1e40af;' : 
                                               (strpos($size, '40ft') !== false ? 'background-color: #d1fae5; color: #065f46;' : 'background-color: #f3f4f6; color: #374151;');
                                    ?>
                                    <span style="padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; <?= $sizeColor ?>">
                                        <?= htmlspecialchars($size) ?>
                                    </span>
                                </td>
                                <td style="font-weight: bold;">
                                    <?= htmlspecialchars($c['container_number_1'] ?? '') ?>
                                    <?php if (!empty($c['container_number_2'])): ?>
                                        <br><span style="font-size: 10px; color: #666;"><?= htmlspecialchars($c['container_number_2']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($c['from_location_name'])): ?>
                                        <?= $c['from_location_name'] ? htmlspecialchars($c['from_location_name']) : '—' ?>
                                    <?php else: ?>
                                        <?= $booking['from_location'] ? htmlspecialchars($booking['from_location']) : '—' ?>
                                    <?php endif; ?>
                                </td>
                                <td>
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
            <?php endif; ?>
        </div>

        <!-- Terms and Conditions -->
        <div class="terms-section">
            <strong>TERMS & CONDITIONS:</strong><br>
            1. This booking receipt is valid for the transportation services mentioned above.<br>
            2. Any changes to the booking must be communicated in advance.<br>
            3. Container details are subject to verification at pickup and delivery.<br>
            4. ABSUMA Logistics reserves the right to modify routes based on operational requirements.<br>
            5. All disputes are subject to Chandigarh jurisdiction only.<br>
            6. This is a computer-generated document and does not require physical signature.
        </div>

        <!-- Footer -->
        <div class="footer">
            <div>
                <strong>Thank you for choosing ABSUMA Logistics!</strong><br>
                For any queries, contact: +91-172-4567890<br>
                Email: support@absumalogistics.com
            </div>
            <div style="text-align: right;">
                Generated by: <?= htmlspecialchars($_SESSION['full_name']) ?><br>
                Date & Time: <?= date('d-M-Y H:i:s', time()) ?><br>
                System Ref: <?= $booking['booking_id'] ?>
            </div>
        </div>
    </div>

    <!-- WhatsApp Modal -->
    <div id="whatsappModal" class="no-print" style="display: none;">
        <div class="relative">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900 flex items-center">
                        <i class="fab fa-whatsapp text-teal-600 mr-2"></i>
                        Share via WhatsApp
                    </h3>
                    <button onclick="closeWhatsAppModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select User</label>
                    <select id="userSelect" class="input-enhanced">
                        <option value="">Select a user...</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user['phone_number']) ?>" data-name="<?= htmlspecialchars($user['full_name']) ?>">
                                <?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['phone_number']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Select from users with phone numbers in the system</p>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Message (Optional)</label>
                    <textarea id="whatsappMessage" rows="8" placeholder="Add a custom message..." 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"><?php
// Build simplified WhatsApp message
$message = "*BOOKING RECEIPT*\n\n";
$message .= "*Booking ID:* " . htmlspecialchars($booking['booking_id']) . "\n";
$message .= "*Client:* " . htmlspecialchars($booking['client_name']) . "\n";
$message .= "*Client ID:* " . htmlspecialchars($booking['client_code'] ?? 'N/A') . "\n";
$message .= "*Date:* " . date('d M Y', strtotime($booking['created_at'])) . "\n";
$message .= "*Route:* " . htmlspecialchars($booking['from_location'] ?? 'N/A') . " → " . htmlspecialchars($booking['to_location'] ?? 'N/A') . "\n";

if (!empty($containers)) {
    $message .= "*Total Containers:* " . count($containers) . "\n";
}

$message .= "\n*PDF Link:*\n";
$message .= "https://" . $_SERVER['HTTP_HOST'] . "/booking/generate_booking_pdf.php?booking_id=" . urlencode($booking['booking_id']);

// Don't HTML encode the entire message to preserve emojis
echo $message;
?></textarea>
                </div>
                <div class="flex gap-3 justify-end">
                    <button onclick="closeWhatsAppModal()" 
                            class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg text-sm font-medium transition-colors">
                        Cancel
                    </button>
                    <button onclick="sendWhatsApp()" 
                            class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium transition-colors">
                        <i class="fab fa-whatsapp mr-1"></i>
                        Send
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Ensure modal is hidden on page load
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('whatsappModal');
            if (modal) {
                modal.style.display = 'none';
            }
            
            // Add event listener to WhatsApp button as backup
            const whatsappBtn = document.getElementById('whatsappBtn');
            if (whatsappBtn) {
                whatsappBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    shareToWhatsApp();
                });
            }
        });

        // Removed auto-print functionality to prevent blank page issues
        // Users can now use the "Print/Download PDF" button manually
        
        // Hide modal before printing
        window.addEventListener('beforeprint', function() {
            const modal = document.getElementById('whatsappModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
            }
        });
        
        // Combined Print/Download PDF function
        function printAndDownloadPDF() {
            // Ensure modal is hidden before printing
            const modal = document.getElementById('whatsappModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
            }
            window.print();
        }
        
        // WhatsApp modal functions
        function shareToWhatsApp() {
            const modal = document.getElementById('whatsappModal');
            if (modal) {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            } else {
                console.error('Modal element not found');
            }
        }
        
        function closeWhatsAppModal() {
            const modal = document.getElementById('whatsappModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }
        
        function sendWhatsApp() {
            const userSelect = document.getElementById('userSelect');
            const selectedOption = userSelect.options[userSelect.selectedIndex];
            const message = document.getElementById('whatsappMessage').value.trim();
            
            if (!userSelect.value) {
                alert('Please select a user');
                return;
            }
            
            const phoneNumber = userSelect.value;
            
            // Clean phone number (remove spaces, dashes, etc.)
            const cleanPhone = phoneNumber.replace(/[\s\-\(\)]/g, '');
            
            // Create WhatsApp URL with message
            const whatsappUrl = `https://wa.me/${cleanPhone}?text=${encodeURIComponent(message)}`;
            
            // Open WhatsApp in new tab
            window.open(whatsappUrl, '_blank');
            
            // Close modal
            closeWhatsAppModal();
        }
        
        // Close modal when clicking outside
        document.getElementById('whatsappModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeWhatsAppModal();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeWhatsAppModal();
            }
        });
        
        // Enhanced print styles
        window.addEventListener('beforeprint', function() {
            document.body.style.backgroundColor = 'white';
        });
    </script>
</body>
</html>
