<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

$trip_id = $_GET['trip_id'] ?? null;
$action = $_GET['action'] ?? 'view'; // 'view' or 'download'

if (!$trip_id || !is_numeric($trip_id)) {
    die('Invalid trip ID');
}

try {
    // Fetch trip details with all related information
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            c.client_name,
            c.client_code,
            c.contact_person as client_contact,
            c.phone_number as client_phone,
            c.email_address as client_email,
            c.billing_address as client_address,
            CASE 
                WHEN t.vehicle_type = 'vendor' THEN 
                    CONCAT(vv.vehicle_number)
                ELSE 
                    CONCAT(ov.vehicle_number)
            END as vehicle_number,
            CASE 
                WHEN t.vehicle_type = 'vendor' THEN 
                    CONCAT(COALESCE(vv.make, ''), ' ', COALESCE(vv.model, ''))
                ELSE 
                    ov.make_model
            END as vehicle_details,
            CASE 
                WHEN t.vehicle_type = 'vendor' THEN vv.driver_name
                ELSE ov.driver_name
            END as driver_name,
            CASE 
                WHEN t.vehicle_type = 'vendor' THEN v.company_name
                ELSE 'Owned Vehicle'
            END as vehicle_owner
        FROM trips t
        LEFT JOIN clients c ON t.client_id = c.id
        LEFT JOIN vendor_vehicles vv ON t.vehicle_id = vv.id AND t.vehicle_type = 'vendor'
        LEFT JOIN vendors v ON vv.vendor_id = v.id
        LEFT JOIN vehicles ov ON t.vehicle_id = ov.id AND t.vehicle_type = 'owned'
        WHERE t.id = ?
    ");
    $stmt->execute([$trip_id]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trip) {
        die('Trip not found');
    }

    // Fetch container numbers
    $containerStmt = $pdo->prepare("
        SELECT container_number, container_photo_1, container_photo_2 
        FROM trip_containers 
        WHERE trip_id = ? 
        ORDER BY id
    ");
    $containerStmt->execute([$trip_id]);
    $containers = $containerStmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers for PDF download if requested
    if ($action === 'download') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Trip_Receipt_' . $trip['reference_number'] . '_' . date('Y-m-d') . '.pdf"');
        // Note: This will prompt browser to save as PDF, but content will be HTML
        // For true PDF conversion, browser's print-to-PDF feature will be used
    }

} catch (Exception $e) {
    die('Error fetching trip data: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Receipt - <?= htmlspecialchars($trip['reference_number']) ?></title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
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
            border-bottom: 3px solid #e53e3e;
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
            background-color: #e53e3e;
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
            color: #e53e3e;
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
            background-color: #e53e3e;
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
            background-color: #f8f9fa;
            padding: 10px;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
            color: #e53e3e;
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
        .status-completed { background-color: #d1fae5; color: #065f46; }
        .status-in-progress { background-color: #dbeafe; color: #1e40af; }
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
            background-color: #fef2f2;
            font-weight: bold;
            color: #555;
        }
        
        .container-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .multiple-movements-alert {
            background-color: #fff3cd;
            color: #856404;
            padding: 10px;
            text-align: center;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            margin: 10px 0;
            font-weight: bold;
        }
        
        .terms-section {
            font-size: 10px;
            line-height: 1.5;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
        }
        
        .footer {
            border-top: 2px solid #e53e3e;
            padding-top: 10px;
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #666;
        }
        
        .qr-section {
            position: absolute;
            right: 20px;
            bottom: 80px;
            text-align: center;
            font-size: 8px;
        }
        
        .qr-placeholder {
            width: 60px;
            height: 60px;
            border: 2px solid #e53e3e;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 5px;
            font-size: 8px;
            background: #fef2f2;
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
            background-color: #e53e3e;
            color: white;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Print/Download Buttons -->
        <div class="print-buttons no-print">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print / Save as PDF
            </button>
            <a href="?trip_id=<?= $trip_id ?>&action=download" class="btn btn-secondary">
                <i class="fas fa-download"></i> Download HTML
            </a>
            <a href="create_trip.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Trips
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
                <div class="receipt-badge">TRIP RECEIPT</div>
                <div class="reference-number"><?= htmlspecialchars($trip['reference_number']) ?></div>
                <div class="generated-date">Generated: <?= date('d-M-Y H:i') ?></div>
            </div>
        </div>

        <!-- Trip Information -->
        <div class="section">
            <div class="section-header">TRIP INFORMATION</div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Trip Date:</span>
                    <span class="info-value"><?= date('d-M-Y', strtotime($trip['trip_date'])) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Booking Number:</span>
                    <span class="info-value"><?= htmlspecialchars($trip['booking_number']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Container Type:</span>
                    <span class="info-value"><?= strtoupper($trip['container_type']) ?> Container</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Status:</span>
                    <span class="info-value">
                        <span class="status-badge status-<?= $trip['status'] ?>">
                            <?= strtoupper($trip['status']) ?>
                        </span>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">From Location:</span>
                    <span class="info-value"><?= htmlspecialchars($trip['from_location']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">To Location:</span>
                    <span class="info-value"><?= htmlspecialchars($trip['to_location']) ?></span>
                </div>
            </div>
            <?php if ($trip['multiple_movements']): ?>
                <div class="multiple-movements-alert">
                    ⚠️ This booking contains multiple movements
                </div>
            <?php endif; ?>
        </div>

        <!-- Client Information -->
        <div class="section">
            <div class="section-header">CLIENT INFORMATION</div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Client Name:</span>
                    <span class="info-value"><?= htmlspecialchars($trip['client_name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Client Code:</span>
                    <span class="info-value"><?= htmlspecialchars($trip['client_code']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Contact Person:</span>
                    <span class="info-value"><?= htmlspecialchars($trip['client_contact'] ?: 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Phone:</span>
                    <span class="info-value"><?= htmlspecialchars($trip['client_phone'] ?: 'N/A') ?></span>
                </div>
            </div>
            <div class="info-item" style="grid-column: 1 / -1;">
                <span class="info-label">Email:</span>
                <span class="info-value"><?= htmlspecialchars($trip['client_email'] ?: 'N/A') ?></span>
            </div>
        </div>

        <!-- Vehicle Information -->
        <div class="section">
            <div class="section-header">VEHICLE INFORMATION</div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Vehicle Number:</span>
                    <span class="info-value"><?= htmlspecialchars($trip['vehicle_number']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Vehicle Type:</span>
                    <span class="info-value"><?= htmlspecialchars($trip['vehicle_owner']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Vehicle Details:</span>
                    <span class="info-value"><?= htmlspecialchars(trim($trip['vehicle_details']) ?: 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Driver Name:</span>
                    <span class="info-value"><?= htmlspecialchars($trip['driver_name'] ?: 'N/A') ?></span>
                </div>
                <?php if ($trip['is_vendor_vehicle'] && $trip['vendor_rate']): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <span class="info-label">Vendor Rate:</span>
                    <span class="info-value">₹ <?= number_format($trip['vendor_rate'], 2) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Container Information -->
        <div class="section">
            <div class="section-header">CONTAINER INFORMATION</div>
            <table class="container-table">
                <thead>
                    <tr>
                        <th style="width: 10%;">S.No.</th>
                        <th style="width: 60%;">Container Number</th>
                        <th style="width: 30%;">Photos Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($containers as $index => $container): ?>
                        <?php
                        $photoInfo = [];
                        if ($container['container_photo_1']) $photoInfo[] = 'Photo 1: ✓';
                        if ($container['container_photo_2']) $photoInfo[] = 'Photo 2: ✓';
                        $photoStatus = !empty($photoInfo) ? implode(', ', $photoInfo) : 'No photos';
                        ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td style="font-weight: bold;"><?= htmlspecialchars($container['container_number']) ?></td>
                            <td style="font-size: 10px; color: #666;"><?= $photoStatus ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Terms and Conditions -->
        <div class="terms-section">
            <strong>TERMS & CONDITIONS:</strong><br>
            1. This receipt is valid for the transportation services mentioned above.<br>
            2. Any damage or loss of goods during transit should be reported immediately.<br>
            3. Container photos are taken for verification purposes at pickup and delivery.<br>
            4. ABSUMA Logistics is not liable for any damage due to improper packing.<br>
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
                Date & Time: <?= date('d-M-Y H:i:s') ?><br>
                System Ref: <?= $trip['reference_number'] ?>
            </div>
        </div>

        <!-- QR Code Placeholder -->
        <div class="qr-section no-print">
            <div class="qr-placeholder">QR CODE</div>
            <div>Scan for verification</div>
        </div>
    </div>

    <script>
        // Auto-print when download action is requested
        <?php if ($action === 'download'): ?>
        window.onload = function() {
            window.print();
        };
        <?php endif; ?>
        
        // Print function
        function printReceipt() {
            window.print();
        }
        
        // Enhanced print styles
        window.addEventListener('beforeprint', function() {
            document.body.style.backgroundColor = 'white';
        });
    </script>
</body>
</html>