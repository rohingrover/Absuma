<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';



// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$client_id = $_GET['client_id'] ?? '';
$vehicle_type = $_GET['vehicle_type'] ?? '';
$container_type = $_GET['container_type'] ?? '';
$status = $_GET['status'] ?? '';
$booking_number = $_GET['booking_number'] ?? '';
$from_location = $_GET['from_location'] ?? '';
$to_location = $_GET['to_location'] ?? '';
$search_term = $_GET['search'] ?? '';
$export_format = $_GET['export'] ?? '';
$debug = $_GET['debug'] ?? ''; // Add debug parameter

// Pagination
$page = (int)($_GET['page'] ?? 1);
$limit = (int)($_GET['limit'] ?? 25);
$offset = ($page - 1) * $limit;

$error = '';
$trips = [];
$summary = [];
$totalRecords = 0;
$totalPages = 0;
$totalPages = 0;

try {
    // Simple check - get total trip count first
    $totalTripsCheck = $pdo->query("SELECT COUNT(*) as total FROM trips")->fetch();
    
    if ($debug) {
        echo "<pre>Debug Info:\n";
        echo "Total trips in database: " . $totalTripsCheck['total'] . "\n";
        echo "Date from: $date_from\n";
        echo "Date to: $date_to\n";
        echo "Client ID: $client_id\n";
        echo "Search term: $search_term\n";
    }

    // Build simplified query first
    $whereConditions = ["1=1"]; // Always true condition
    $params = [];

    // Add date filter
    if ($date_from) {
        $whereConditions[] = "t.trip_date >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $whereConditions[] = "t.trip_date <= ?";
        $params[] = $date_to;
    }

    // Add other filters
    if ($client_id) {
        $whereConditions[] = "t.client_id = ?";
        $params[] = $client_id;
    }
    if ($vehicle_type) {
        $whereConditions[] = "t.vehicle_type = ?";
        $params[] = $vehicle_type;
    }
    if ($container_type) {
        $whereConditions[] = "t.container_type = ?";
        $params[] = $container_type;
    }
    if ($status) {
        $whereConditions[] = "t.status = ?";
        $params[] = $status;
    }
    if ($booking_number) {
        $whereConditions[] = "t.booking_number LIKE ?";
        $params[] = "%$booking_number%";
    }
    if ($from_location) {
        $whereConditions[] = "t.from_location LIKE ?";
        $params[] = "%$from_location%";
    }
    if ($to_location) {
        $whereConditions[] = "t.to_location LIKE ?";
        $params[] = "%$to_location%";
    }
    if ($search_term) {
        $whereConditions[] = "(t.reference_number LIKE ? OR t.booking_number LIKE ? OR c.client_name LIKE ?)";
        $searchParam = "%$search_term%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    if ($debug) {
        echo "WHERE clause: $whereClause\n";
        echo "Parameters: " . print_r($params, true) . "\n";
        echo "</pre>";
    }

    // Simplified main query for debugging
    $mainQuery = "
        SELECT 
            t.id,
            t.reference_number,
            t.trip_date,
            t.booking_number,
            t.container_type,
            t.from_location,
            t.to_location,
            t.status,
            t.vehicle_type,
            t.vehicle_id,
            t.vendor_rate,
            t.is_vendor_vehicle,
            t.multiple_movements,
            c.client_name,
            c.client_code,
            c.contact_person as client_contact,
            -- Simple rate calculation
            COALESCE(t.vendor_rate, 0) as calculated_rate,
            CASE 
                WHEN t.vendor_rate IS NOT NULL AND t.is_vendor_vehicle = 1 THEN 'vendor'
                ELSE 'none'
            END as rate_source,
            -- Vehicle information
            CASE 
                WHEN t.vehicle_type = 'vendor' THEN 
                    (SELECT CONCAT(vv.vehicle_number) FROM vendor_vehicles vv WHERE vv.id = t.vehicle_id LIMIT 1)
                ELSE 
                    (SELECT CONCAT(ov.vehicle_number) FROM vehicles ov WHERE ov.id = t.vehicle_id LIMIT 1)
            END as vehicle_number,
            CASE 
                WHEN t.vehicle_type = 'vendor' THEN 
                    (SELECT vv.driver_name FROM vendor_vehicles vv WHERE vv.id = t.vehicle_id LIMIT 1)
                ELSE 
                    (SELECT ov.driver_name FROM vehicles ov WHERE ov.id = t.vehicle_id LIMIT 1)
            END as driver_name,
            CASE 
                WHEN t.vehicle_type = 'vendor' THEN 
                    (SELECT v.company_name FROM vendor_vehicles vv LEFT JOIN vendors v ON vv.vendor_id = v.id WHERE vv.id = t.vehicle_id LIMIT 1)
                ELSE 
                    'Owned Vehicle'
            END as vehicle_owner,
            -- Container information
            (SELECT GROUP_CONCAT(tc.container_number ORDER BY tc.id SEPARATOR ', ') 
             FROM trip_containers tc 
             WHERE tc.trip_id = t.id) as container_numbers,
            (SELECT COUNT(tc.id) 
             FROM trip_containers tc 
             WHERE tc.trip_id = t.id) as container_count
        FROM trips t
        LEFT JOIN clients c ON t.client_id = c.id
        $whereClause
        ORDER BY t.trip_date DESC, t.created_at DESC
    ";

    // Get total count for pagination
    $countQuery = "
        SELECT COUNT(t.id) as total_count
        FROM trips t
        LEFT JOIN clients c ON t.client_id = c.id
        $whereClause
    ";

    if ($debug) {
        echo "<pre>Count Query: $countQuery</pre>";
        echo "<pre>Main Query: $mainQuery</pre>";
    }

    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetch()['total_count'];
    $totalPages = ceil($totalRecords / $limit);

    if ($debug) {
        echo "<pre>Total records found: $totalRecords</pre>";
    }

    // Handle export requests
    if ($export_format && in_array($export_format, ['csv', 'excel'])) {
        // Get all records for export (no pagination)
        $exportStmt = $pdo->prepare($mainQuery);
        $exportStmt->execute($params);
        $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($export_format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="trip_reports_' . date('Y-m-d_H-i-s') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($output, [
                'Reference Number', 'Trip Date', 'Booking Number', 'Client Name', 'Client Code',
                'Container Type', 'Container Numbers', 'From Location', 'To Location',
                'Vehicle Number', 'Vehicle Type', 'Driver Name', 'Status', 'Rate (₹)', 'Rate Source'
            ]);
            
            // CSV data
            foreach ($exportData as $row) {
                fputcsv($output, [
                    $row['reference_number'],
                    $row['trip_date'],
                    $row['booking_number'],
                    $row['client_name'],
                    $row['client_code'],
                    strtoupper($row['container_type']),
                    $row['container_numbers'] ?: 'No containers',
                    $row['from_location'],
                    $row['to_location'],
                    $row['vehicle_number'] ?: 'No vehicle',
                    $row['vehicle_owner'],
                    $row['driver_name'] ?: 'No driver',
                    strtoupper($row['status']),
                    number_format($row['calculated_rate'], 2),
                    strtoupper($row['rate_source'])
                ]);
            }
            
            fclose($output);
            exit;
        }
    }

    // Get paginated results
    $paginatedQuery = $mainQuery . " LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($paginatedQuery);
    $stmt->execute($params);
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($debug) {
        echo "<pre>Trips found: " . count($trips) . "</pre>";
        if (!empty($trips)) {
            echo "<pre>First trip: " . print_r($trips[0], true) . "</pre>";
        }
    }

    // Get summary statistics
    $summaryQuery = "
        SELECT 
            COUNT(t.id) as total_trips,
            COUNT(DISTINCT t.client_id) as unique_clients,
            COUNT(DISTINCT t.booking_number) as unique_bookings,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_trips,
            SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_trips,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_trips,
            SUM(CASE WHEN t.container_type = '20ft' THEN 1 ELSE 0 END) as trips_20ft,
            SUM(CASE WHEN t.container_type = '40ft' THEN 1 ELSE 0 END) as trips_40ft,
            SUM(CASE WHEN t.vehicle_type = 'owned' THEN 1 ELSE 0 END) as owned_vehicle_trips,
            SUM(CASE WHEN t.vehicle_type = 'vendor' THEN 1 ELSE 0 END) as vendor_vehicle_trips,
            AVG(COALESCE(t.vendor_rate, 0)) as avg_rate,
            SUM(COALESCE(t.vendor_rate, 0)) as total_revenue
        FROM trips t
        LEFT JOIN clients c ON t.client_id = c.id
        $whereClause
    ";

    $summaryStmt = $pdo->prepare($summaryQuery);
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    // Get client list for filter dropdown
    $clientsStmt = $pdo->query("SELECT id, client_name, client_code FROM clients WHERE status = 'active' AND deleted_at IS NULL ORDER BY client_name");
    $clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = $e->getMessage();
    if ($debug) {
        echo "<pre>Error: " . $error . "</pre>";
        echo "<pre>Stack trace: " . $e->getTraceAsString() . "</pre>";
    }
    $trips = [];
    $summary = [
        'total_trips' => 0,
        'unique_clients' => 0,
        'unique_bookings' => 0,
        'completed_trips' => 0,
        'pending_trips' => 0,
        'in_progress_trips' => 0,
        'trips_20ft' => 0,
        'trips_40ft' => 0,
        'owned_vehicle_trips' => 0,
        'vendor_vehicle_trips' => 0,
        'avg_rate' => 0,
        'total_revenue' => 0
    ];
    $totalRecords = 0;
    $totalPages = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Reports - Absuma Logistics Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'absuma-red': '#e53e3e',
                        'absuma-red-dark': '#c53030',
                        primary: {
                            light: '#fed7d7',
                            DEFAULT: '#e53e3e',
                            dark: '#c53030',
                        },
                        secondary: {
                            light: '#6ee7b7',
                            DEFAULT: '#10b981',
                            dark: '#059669',
                        },
                        accent: {
                            light: '#fcd34d',
                            DEFAULT: '#f59e0b',
                            dark: '#d97706',
                        },
                        dark: '#1e293b',
                        light: '#f8fafc',
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.4s ease-out forwards',
                        'slide-down': 'slideDown 0.3s ease-out forwards',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: 0, transform: 'translateY(20px)' },
                            '100%': { opacity: 1, transform: 'translateY(0)' }
                        },
                        slideDown: {
                            '0%': { opacity: 0, transform: 'translateY(-10px)' },
                            '100%': { opacity: 1, transform: 'translateY(0)' }
                        }
                    },
                    boxShadow: {
                        'soft': '0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025)',
                        'glow': '0 0 15px -3px rgba(229, 62, 62, 0.3)',
                        'glow-blue': '0 0 15px -3px rgba(59, 130, 246, 0.3)',
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
        
        .absuma-gradient {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
        }
        
        .stats-card {
            @apply bg-white rounded-xl shadow-soft p-6 border-l-4 transition-all duration-300 hover:shadow-glow-blue hover:-translate-y-1;
        }
        
        .card-hover-effect {
            @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
        }

        .form-input {
            @apply w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-absuma-red focus:border-absuma-red transition-all;
        }
        
        .table-hover-effect {
            @apply hover:bg-red-50/50 transition-colors;
        }

        .filter-section {
            @apply bg-white rounded-xl shadow-soft p-6 border border-gray-200;
        }

        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen">
        <!-- Header with Absuma Branding -->
        <?php include 'header_component.php'; ?>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar with Absuma colors -->
                <aside class="w-full lg:w-64 flex-shrink-0 no-print">
                    <div class="bg-white rounded-xl shadow-soft p-4 sticky top-20 border border-white/20 backdrop-blur-sm bg-white/70">
                        <!-- Trip Management Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Trip Management</h3>
                            <nav class="space-y-1.5">
                                <a href="create_trip.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Create Trip
                                </a>
                                <a href="manage_trips.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Trips
                                </a>
                                <a href="trip_reports.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium absuma-gradient text-white rounded-lg transition-all">
                                    <i class="fas fa-chart-line w-5 text-center"></i>Trip Reports
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Export Options -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Export Options</h3>
                            <nav class="space-y-1.5">
                                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-green-50 hover:text-green-600 rounded-lg transition-all group">
                                    <i class="fas fa-file-csv w-5 text-center text-green-600 group-hover:text-green-600"></i>Export CSV
                                </a>
                                <button onclick="window.print()" class="w-full flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all group">
                                    <i class="fas fa-print w-5 text-center text-blue-600 group-hover:text-blue-600"></i>Print Report
                                </button>
                            </nav>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="bg-gradient-to-br from-blue-50 to-indigo-100 rounded-lg p-4 border border-blue-200">
                            <h4 class="text-sm font-semibold text-blue-800 mb-2 flex items-center">
                                <i class="fas fa-info-circle mr-2"></i>Quick Stats
                            </h4>
                            <div class="text-xs text-blue-700 space-y-1">
                                <div>Total Trips: <span class="font-bold"><?= number_format($summary['total_trips'] ?? 0) ?></span></div>
                                <div>Total Revenue: <span class="font-bold">₹<?= number_format($summary['total_revenue'] ?? 0, 2) ?></span></div>
                                <div>Avg Rate: <span class="font-bold">₹<?= number_format($summary['avg_rate'] ?? 0, 2) ?></span></div>
                                <div>Clients: <span class="font-bold"><?= number_format($summary['unique_clients'] ?? 0) ?></span></div>
                            </div>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1">
                    <div class="space-y-6">
                        <!-- Welcome Section -->
                        <div class="bg-white rounded-xl shadow-soft p-6 border-l-4 border-absuma-red no-print">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-800 mb-2">
                                        Trip Reports & Analytics
                                    </h2>
                                    <p class="text-gray-600">Comprehensive trip analysis with rate calculations and filtering</p>
                                </div>
                                <div class="hidden md:block">
                                    <div class="bg-red-50 p-4 rounded-lg">
                                        <i class="fas fa-chart-bar text-3xl text-absuma-red"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Summary Statistics -->
                        <?php if (!empty($summary)): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 no-print">
                            <!-- Total Trips -->
                            <div class="stats-card border-blue-300 animate-fade-in">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-blue-200/70 flex items-center justify-center text-blue-700 shadow-inner">
                                        <i class="fas fa-truck text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-blue-800">Total Trips</p>
                                        <div class="flex items-end gap-2">
                                            <p class="text-2xl font-bold text-blue-900"><?= number_format($summary['total_trips']) ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Total Revenue -->
                            <div class="stats-card border-green-300 animate-fade-in">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-green-200/70 flex items-center justify-center text-green-700 shadow-inner">
                                        <i class="fas fa-rupee-sign text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-green-800">Total Revenue</p>
                                        <div class="flex items-end gap-2">
                                            <p class="text-2xl font-bold text-green-900">₹<?= number_format($summary['total_revenue'], 0) ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Completed Trips -->
                            <div class="stats-card border-purple-300 animate-fade-in">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-purple-200/70 flex items-center justify-center text-purple-700 shadow-inner">
                                        <i class="fas fa-check-circle text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-purple-800">Completed</p>
                                        <div class="flex items-end gap-2">
                                            <p class="text-2xl font-bold text-purple-900"><?= number_format($summary['completed_trips']) ?></p>
                                            <p class="text-xs text-purple-600"><?= $summary['total_trips'] > 0 ? round(($summary['completed_trips'] / $summary['total_trips']) * 100, 1) : 0 ?>%</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Average Rate -->
                            <div class="stats-card border-orange-300 animate-fade-in">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-orange-200/70 flex items-center justify-center text-orange-700 shadow-inner">
                                        <i class="fas fa-calculator text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-orange-800">Avg Rate</p>
                                        <div class="flex items-end gap-2">
                                            <p class="text-2xl font-bold text-orange-900">₹<?= number_format($summary['avg_rate'], 0) ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Filters Section -->
                        <div class="filter-section no-print">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                                <i class="fas fa-filter text-absuma-red mr-2"></i>
                                Advanced Filters
                            </h3>
                            <form method="GET" class="space-y-4">
                                <!-- Date Range and Search -->
                                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="form-input">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                                        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="form-input">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Records per page</label>
                                        <select name="limit" class="form-input">
                                            <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                                            <option value="250" <?= $limit == 250 ? 'selected' : '' ?>>250</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                        <div class="relative">
                                            <input type="text" name="search" value="<?= htmlspecialchars($search_term) ?>"
                                                   placeholder="Reference, booking, client..." 
                                                   class="form-input pl-10">
                                            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- Dropdown Filters -->
                                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                                        <select name="client_id" class="form-input">
                                            <option value="">All Clients</option>
                                            <?php foreach ($clients as $client): ?>
                                                <option value="<?= $client['id'] ?>" <?= $client_id == $client['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($client['client_name']) ?> (<?= htmlspecialchars($client['client_code']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle Type</label>
                                        <select name="vehicle_type" class="form-input">
                                            <option value="">All Types</option>
                                            <option value="owned" <?= $vehicle_type === 'owned' ? 'selected' : '' ?>>Owned</option>