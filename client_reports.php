<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Get report parameters
$report_type = $_GET['report_type'] ?? 'overview';
$date_range = $_GET['date_range'] ?? '30';
$client_id = $_GET['client_id'] ?? '';
$export_format = $_GET['export'] ?? '';

// Calculate date range
$end_date = date('Y-m-d');
switch ($date_range) {
    case '7':
        $start_date = date('Y-m-d', strtotime('-7 days'));
        break;
    case '30':
        $start_date = date('Y-m-d', strtotime('-30 days'));
        break;
    case '90':
        $start_date = date('Y-m-d', strtotime('-90 days'));
        break;
    case '365':
        $start_date = date('Y-m-d', strtotime('-365 days'));
        break;
    default:
        $start_date = date('Y-m-d', strtotime('-30 days'));
}

// Override with custom date range if provided
if (isset($_GET['start_date']) && isset($_GET['end_date'])) {
    $start_date = $_GET['start_date'];
    $end_date = $_GET['end_date'];
}

// Get client overview statistics
function getClientOverview($pdo) {
    return $pdo->query("
        SELECT 
            COUNT(*) as total_clients,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_clients,
            COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_clients,
            COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended_clients,
            AVG(billing_cycle_days) as avg_billing_cycle,
            COUNT(CASE WHEN pan_number IS NOT NULL AND pan_number != '' THEN 1 END) as clients_with_pan,
            COUNT(CASE WHEN gst_number IS NOT NULL AND gst_number != '' THEN 1 END) as clients_with_gst
        FROM clients 
        WHERE deleted_at IS NULL
    ")->fetch(PDO::FETCH_ASSOC);
}

// Get client registration trends
function getRegistrationTrends($pdo, $start_date, $end_date) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as registrations
        FROM clients 
        WHERE created_at BETWEEN ? AND ? 
        AND deleted_at IS NULL
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get client status distribution
function getStatusDistribution($pdo) {
    return $pdo->query("
        SELECT 
            status,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL)), 1) as percentage
        FROM clients 
        WHERE deleted_at IS NULL
        GROUP BY status
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Get rate analysis
function getRateAnalysis($pdo) {
    return $pdo->query("
        SELECT 
            container_size,
            movement_type,
            container_type,
            import_export,
            COUNT(*) as rate_count,
            MIN(rate) as min_rate,
            MAX(rate) as max_rate,
            AVG(rate) as avg_rate,
            STDDEV(rate) as rate_stddev
        FROM client_rates cr
        JOIN clients c ON cr.client_id = c.id
        WHERE cr.deleted_at IS NULL AND c.deleted_at IS NULL
        GROUP BY container_size, movement_type, container_type, import_export
        ORDER BY container_size, avg_rate DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Get top clients by rate count
function getTopClientsByRates($pdo, $limit = 10) {
    $stmt = $pdo->prepare("
        SELECT 
            c.client_name,
            c.client_code,
            c.contact_person,
            c.status,
            COUNT(cr.id) as rate_count,
            AVG(cr.rate) as avg_rate,
            MIN(cr.rate) as min_rate,
            MAX(cr.rate) as max_rate
        FROM clients c
        LEFT JOIN client_rates cr ON c.id = cr.client_id AND cr.deleted_at IS NULL
        WHERE c.deleted_at IS NULL
        GROUP BY c.id, c.client_name, c.client_code, c.contact_person, c.status
        ORDER BY rate_count DESC, avg_rate DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get billing cycle analysis
function getBillingCycleAnalysis($pdo) {
    return $pdo->query("
        SELECT 
            billing_cycle_days,
            COUNT(*) as client_count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL)), 1) as percentage
        FROM clients 
        WHERE deleted_at IS NULL
        GROUP BY billing_cycle_days
        ORDER BY client_count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Get client details report
function getClientDetailsReport($pdo, $client_id = null) {
    $query = "
        SELECT 
            c.*,
            COUNT(DISTINCT cr.id) as total_rates,
            COUNT(DISTINCT CASE WHEN cr.container_size = '20ft' THEN cr.id END) as rates_20ft,
            COUNT(DISTINCT CASE WHEN cr.container_size = '40ft' THEN cr.id END) as rates_40ft,
            AVG(cr.rate) as avg_rate,
            MIN(cr.rate) as min_rate,
            MAX(cr.rate) as max_rate
        FROM clients c
        LEFT JOIN client_rates cr ON c.id = cr.client_id AND cr.deleted_at IS NULL
        WHERE c.deleted_at IS NULL
    ";
    
    $params = [];
    if ($client_id) {
        $query .= " AND c.id = ?";
        $params[] = $client_id;
    }
    
    $query .= "
        GROUP BY c.id
        ORDER BY c.client_name ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all clients for dropdown
function getAllClients($pdo) {
    return $pdo->query("
        SELECT id, client_name, client_code 
        FROM clients 
        WHERE deleted_at IS NULL 
        ORDER BY client_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch data based on report type
$data = [];
$clients_list = getAllClients($pdo);

switch ($report_type) {
    case 'overview':
        $data = [
            'overview' => getClientOverview($pdo),
            'trends' => getRegistrationTrends($pdo, $start_date, $end_date),
            'status_dist' => getStatusDistribution($pdo),
            'top_clients' => getTopClientsByRates($pdo, 5)
        ];
        break;
    
    case 'rates':
        $data = [
            'rate_analysis' => getRateAnalysis($pdo),
            'top_clients' => getTopClientsByRates($pdo, 10)
        ];
        break;
    
    case 'billing':
        $data = [
            'billing_cycles' => getBillingCycleAnalysis($pdo),
            'overview' => getClientOverview($pdo)
        ];
        break;
    
    case 'detailed':
        $data = [
            'clients' => getClientDetailsReport($pdo, $client_id)
        ];
        break;
}

// Handle export
if ($export_format) {
    handleExport($export_format, $data, $report_type);
}

function handleExport($format, $data, $report_type) {
    switch ($format) {
        case 'csv':
            exportCSV($data, $report_type);
            break;
        case 'json':
            exportJSON($data, $report_type);
            break;
    }
}

function exportCSV($data, $report_type) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="client_report_' . $report_type . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if ($report_type === 'detailed' && isset($data['clients'])) {
        // CSV headers
        fputcsv($output, [
            'Client Code', 'Client Name', 'Contact Person', 'Phone', 'Email',
            'Billing Cycle', 'PAN', 'GST', 'Status', 'Total Rates', '20ft Rates',
            '40ft Rates', 'Avg Rate', 'Min Rate', 'Max Rate', 'Created Date'
        ]);
        
        // CSV data
        foreach ($data['clients'] as $client) {
            fputcsv($output, [
                $client['client_code'],
                $client['client_name'],
                $client['contact_person'],
                $client['phone_number'],
                $client['email_address'],
                $client['billing_cycle_days'] . ' days',
                $client['pan_number'] ?: 'N/A',
                $client['gst_number'] ?: 'N/A',
                ucfirst($client['status']),
                $client['total_rates'],
                $client['rates_20ft'],
                $client['rates_40ft'],
                $client['avg_rate'] ? '₹' . number_format($client['avg_rate'], 2) : 'N/A',
                $client['min_rate'] ? '₹' . number_format($client['min_rate'], 2) : 'N/A',
                $client['max_rate'] ? '₹' . number_format($client['max_rate'], 2) : 'N/A',
                date('d-M-Y', strtotime($client['created_at']))
            ]);
        }
    }
    
    fclose($output);
    exit;
}

function exportJSON($data, $report_type) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="client_report_' . $report_type . '_' . date('Y-m-d') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Reports - Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="notifications.js"></script>
    <style>
        @layer utilities {
            .badge {
                @apply inline-block px-2.5 py-1 rounded-full text-xs font-semibold;
            }
            .badge-active {
                @apply bg-green-100 text-green-800;
            }
            .badge-inactive {
                @apply bg-gray-100 text-gray-800;
            }
            .badge-suspended {
                @apply bg-red-100 text-red-800;
            }
            .card-hover-effect {
                @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
            }
            .stat-card {
                @apply bg-white rounded-xl shadow-sm p-6 card-hover-effect;
            }
            .chart-container {
                @apply bg-white rounded-xl shadow-sm p-6 card-hover-effect;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-white border-b border-gray-200 shadow-sm">
            <div class="max-w-[1340px] mx-auto px-6 py-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-4">
                        <div class="bg-blue-600 p-3 rounded-lg text-white">
                            <i class="fas fa-chart-line text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Fleet Management System</h1>
                            <p class="text-blue-600 text-sm">Client Reports & Analytics</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="bg-blue-50 px-4 py-2 rounded-lg border border-blue-100">
                            <div class="text-blue-800 font-medium"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
                        </div>
                        <?php include 'notification_bell.php'; ?>
                        <a href="logout.php" class="text-blue-600 hover:text-blue-800 p-2">
                            <i class="fas fa-sign-out-alt text-lg"></i>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar -->
                <aside class="w-full lg:w-72 flex-shrink-0">
                    <div class="bg-white rounded-xl shadow-sm p-4 sticky top-20">
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-blue-600 font-bold mb-3 pl-2 border-l-4 border-blue-600/50">Client Section</h3>
                            <nav class="space-y-1">
                                <a href="add_client.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center"></i> Add New Client
                                </a>
                                <a href="manage_clients.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-list w-5 text-center"></i> Manage Clients
                                </a>
                                <a href="client_reports.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg">
                                    <i class="fas fa-chart-line w-5 text-center"></i> Client Reports
                                </a>
                                <a href="dashboard.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-tachometer-alt w-5 text-center"></i> Dashboard
                                </a>
                            </nav>
                        </div>

                        <!-- Report Types -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-green-600 font-bold mb-3 pl-2 border-l-4 border-green-600/50">Report Types</h3>
                            <nav class="space-y-1">
                                <a href="?report_type=overview&date_range=<?= $date_range ?>" class="flex items-center gap-2 px-3 py-2 text-sm font-medium <?= $report_type === 'overview' ? 'text-green-600 bg-green-50' : 'text-gray-700 hover:bg-green-50 hover:text-green-600' ?> rounded-lg transition-all">
                                    <i class="fas fa-chart-pie w-5 text-center"></i> Overview
                                </a>
                                <a href="?report_type=rates&date_range=<?= $date_range ?>" class="flex items-center gap-2 px-3 py-2 text-sm font-medium <?= $report_type === 'rates' ? 'text-green-600 bg-green-50' : 'text-gray-700 hover:bg-green-50 hover:text-green-600' ?> rounded-lg transition-all">
                                    <i class="fas fa-money-bill w-5 text-center"></i> Rate Analysis
                                </a>
                                <a href="?report_type=billing&date_range=<?= $date_range ?>" class="flex items-center gap-2 px-3 py-2 text-sm font-medium <?= $report_type === 'billing' ? 'text-green-600 bg-green-50' : 'text-gray-700 hover:bg-green-50 hover:text-green-600' ?> rounded-lg transition-all">
                                    <i class="fas fa-file-invoice w-5 text-center"></i> Billing Analysis
                                </a>
                                <a href="?report_type=detailed&date_range=<?= $date_range ?>" class="flex items-center gap-2 px-3 py-2 text-sm font-medium <?= $report_type === 'detailed' ? 'text-green-600 bg-green-50' : 'text-gray-700 hover:bg-green-50 hover:text-green-600' ?> rounded-lg transition-all">
                                    <i class="fas fa-table w-5 text-center"></i> Detailed Report
                                </a>
                            </nav>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1">
                    <!-- Filters and Export -->
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                            <div class="flex flex-wrap gap-4">
                                <!-- Date Range Filter -->
                                <div class="flex items-center gap-2">
                                    <label for="date_range" class="text-sm font-medium text-gray-700">Date Range:</label>
                                    <select id="date_range" onchange="updateDateRange()" class="px-3 py-1 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="7" <?= $date_range === '7' ? 'selected' : '' ?>>Last 7 days</option>
                                        <option value="30" <?= $date_range === '30' ? 'selected' : '' ?>>Last 30 days</option>
                                        <option value="90" <?= $date_range === '90' ? 'selected' : '' ?>>Last 90 days</option>
                                        <option value="365" <?= $date_range === '365' ? 'selected' : '' ?>>Last year</option>
                                    </select>
                                </div>

                                <?php if ($report_type === 'detailed'): ?>
                                <!-- Client Filter -->
                                <div class="flex items-center gap-2">
                                    <label for="client_filter" class="text-sm font-medium text-gray-700">Client:</label>
                                    <select id="client_filter" onchange="updateClientFilter()" class="px-3 py-1 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">All Clients</option>
                                        <?php foreach ($clients_list as $client): ?>
                                            <option value="<?= $client['id'] ?>" <?= $client_id == $client['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($client['client_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Export Buttons -->
                            <div class="flex gap-2">
                                <button onclick="exportData('csv')" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-file-csv mr-2"></i> Export CSV
                                </button>
                                <button onclick="exportData('json')" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-file-code mr-2"></i> Export JSON
                                </button>
                                <button onclick="printReport()" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-print mr-2"></i> Print
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if ($report_type === 'overview'): ?>
                        <!-- Overview Dashboard -->
                        <div class="space-y-6">
                            <!-- Key Metrics -->
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                                <div class="stat-card">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                                            <i class="fas fa-users text-blue-600 text-xl"></i>
                                        </div>
                                        <div class="ml-4">
                                            <p class="text-sm font-medium text-gray-600">Total Clients</p>
                                            <p class="text-2xl font-bold text-gray-900"><?= $data['overview']['total_clients'] ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="stat-card">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                                        </div>
                                        <div class="ml-4">
                                            <p class="text-sm font-medium text-gray-600">Active Clients</p>
                                            <p class="text-2xl font-bold text-green-900"><?= $data['overview']['active_clients'] ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="stat-card">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-orange-100 flex items-center justify-center">
                                            <i class="fas fa-calendar text-orange-600 text-xl"></i>
                                        </div>
                                        <div class="ml-4">
                                            <p class="text-sm font-medium text-gray-600">Avg Billing Cycle</p>
                                            <p class="text-2xl font-bold text-orange-900"><?= round($data['overview']['avg_billing_cycle']) ?> days</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="stat-card">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center">
                                            <i class="fas fa-file-invoice text-purple-600 text-xl"></i>
                                        </div>
                                        <div class="ml-4">
                                            <p class="text-sm font-medium text-gray-600">GST Registered</p>
                                            <p class="text-2xl font-bold text-purple-900"><?= $data['overview']['clients_with_gst'] ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Charts Row -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <!-- Status Distribution -->
                                <div class="chart-container">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Client Status Distribution</h3>
                                    <canvas id="statusChart" width="400" height="300"></canvas>
                                </div>

                                <!-- Registration Trends -->
                                <div class="chart-container">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Registration Trends</h3>
                                    <canvas id="trendsChart" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Top Clients Table -->
                            <div class="chart-container">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Top Clients by Rate Configuration</h3>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Container Size</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Movement Type</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Container Type</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Direction</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rate Count</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Rate</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Min Rate</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Max Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($data['rate_analysis'] as $rate): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($rate['container_size']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <?= htmlspecialchars($rate['movement_type']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <?= htmlspecialchars($rate['container_type']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <?= htmlspecialchars($rate['import_export']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <?= $rate['rate_count'] ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-green-600">
                                                    ₹<?= number_format($rate['avg_rate'], 2) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    ₹<?= number_format($rate['min_rate'], 2) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    ₹<?= number_format($rate['max_rate'], 2) ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Top Clients by Rates -->
                            <div class="chart-container">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Top 10 Clients by Rate Configuration</h3>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact Person</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Rates</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Average Rate</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rate Range</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($data['top_clients'] as $index => $client): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    #<?= $index + 1 ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div>
                                                        <div class="font-medium text-gray-900"><?= htmlspecialchars($client['client_name']) ?></div>
                                                        <div class="text-sm text-gray-500"><?= htmlspecialchars($client['client_code']) ?></div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <?= htmlspecialchars($client['contact_person']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                        <?= $client['rate_count'] ?> rates
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-green-600">
                                                    <?= $client['avg_rate'] ? '₹' . number_format($client['avg_rate'], 2) : 'N/A' ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php if ($client['min_rate'] && $client['max_rate']): ?>
                                                        ₹<?= number_format($client['min_rate'], 2) ?> - ₹<?= number_format($client['max_rate'], 2) ?>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($report_type === 'billing'): ?>
                        <!-- Billing Analysis Report -->
                        <div class="space-y-6">
                            <!-- Billing Cycle Statistics -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div class="stat-card">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                                            <i class="fas fa-calendar-check text-green-600 text-xl"></i>
                                        </div>
                                        <div class="ml-4">
                                            <p class="text-sm font-medium text-gray-600">Avg Billing Cycle</p>
                                            <p class="text-2xl font-bold text-green-900"><?= round($data['overview']['avg_billing_cycle']) ?> days</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="stat-card">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                                            <i class="fas fa-file-invoice-dollar text-blue-600 text-xl"></i>
                                        </div>
                                        <div class="ml-4">
                                            <p class="text-sm font-medium text-gray-600">GST Registered</p>
                                            <p class="text-2xl font-bold text-blue-900"><?= $data['overview']['clients_with_gst'] ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="stat-card">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center">
                                            <i class="fas fa-receipt text-purple-600 text-xl"></i>
                                        </div>
                                        <div class="ml-4">
                                            <p class="text-sm font-medium text-gray-600">PAN Registered</p>
                                            <p class="text-2xl font-bold text-purple-900"><?= $data['overview']['clients_with_pan'] ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Billing Cycle Distribution -->
                            <div class="chart-container">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Billing Cycle Distribution</h3>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Billing Cycle</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client Count</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Percentage</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visual</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($data['billing_cycles'] as $cycle): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?= $cycle['billing_cycle_days'] ?> days
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <?= $cycle['client_count'] ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <?= $cycle['percentage'] ?>%
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                                        <div class="bg-blue-600 h-2 rounded-full" style="width: <?= $cycle['percentage'] ?>%"></div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($report_type === 'detailed'): ?>
                        <!-- Detailed Client Report -->
                        <div class="chart-container">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                                Detailed Client Report
                                <?php if ($client_id): ?>
                                    <?php 
                                    $selected_client = array_filter($clients_list, function($c) use ($client_id) { 
                                        return $c['id'] == $client_id; 
                                    });
                                    if (!empty($selected_client)): 
                                        $selected_client = array_values($selected_client)[0];
                                    ?>
                                        - <?= htmlspecialchars($selected_client['client_name']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </h3>
                            
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client Details</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact Info</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Billing Info</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rate Summary</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($data['clients'] as $client): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div>
                                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($client['client_name']) ?></div>
                                                    <div class="text-sm text-gray-500"><?= htmlspecialchars($client['client_code']) ?></div>
                                                    <div class="text-xs text-gray-400">Created: <?= date('d-M-Y', strtotime($client['created_at'])) ?></div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm">
                                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($client['contact_person']) ?></div>
                                                    <div class="text-gray-500"><?= htmlspecialchars($client['phone_number']) ?></div>
                                                    <div class="text-gray-500"><?= htmlspecialchars($client['email_address']) ?></div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm">
                                                    <div class="text-gray-900"><?= $client['billing_cycle_days'] ?> days cycle</div>
                                                    <?php if ($client['pan_number']): ?>
                                                        <div class="text-gray-500">PAN: <?= htmlspecialchars($client['pan_number']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($client['gst_number']): ?>
                                                        <div class="text-gray-500">GST: <?= htmlspecialchars($client['gst_number']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm">
                                                    <div class="text-gray-900">Total: <?= $client['total_rates'] ?> rates</div>
                                                    <div class="text-gray-500">20ft: <?= $client['rates_20ft'] ?> | 40ft: <?= $client['rates_40ft'] ?></div>
                                                    <?php if ($client['avg_rate']): ?>
                                                        <div class="text-green-600 font-medium">Avg: ₹<?= number_format($client['avg_rate'], 2) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="badge badge-<?= strtolower($client['status']) ?>">
                                                    <?= ucfirst($client['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </main>
            </div>
        </div>
    </div>

    <script>
        // Chart.js configurations
        Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
        Chart.defaults.font.size = 12;

        <?php if ($report_type === 'overview'): ?>
        // Status Distribution Pie Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php echo implode(',', array_map(function($s) { return '"' . ucfirst($s['status']) . '"'; }, $data['status_dist'])); ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($data['status_dist'], 'count')); ?>],
                    backgroundColor: ['#10b981', '#6b7280', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });

        // Registration Trends Line Chart
        const trendsCtx = document.getElementById('trendsChart').getContext('2d');
        new Chart(trendsCtx, {
            type: 'line',
            data: {
                labels: [<?php echo implode(',', array_map(function($t) { return '"' . date('M d', strtotime($t['date'])) . '"'; }, $data['trends'])); ?>],
                datasets: [{
                    label: 'New Registrations',
                    data: [<?php echo implode(',', array_column($data['trends'], 'registrations')); ?>],
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        <?php endif; ?>

        // Utility functions
        function updateDateRange() {
            const dateRange = document.getElementById('date_range').value;
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('date_range', dateRange);
            window.location.href = currentUrl.toString();
        }

        function updateClientFilter() {
            const clientId = document.getElementById('client_filter').value;
            const currentUrl = new URL(window.location.href);
            if (clientId) {
                currentUrl.searchParams.set('client_id', clientId);
            } else {
                currentUrl.searchParams.delete('client_id');
            }
            window.location.href = currentUrl.toString();
        }

        function exportData(format) {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('export', format);
            window.location.href = currentUrl.toString();
        }

        function printReport() {
            window.print();
        }

        // Auto-refresh every 5 minutes for live data
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                // Only refresh if the page is visible
                location.reload();
            }
        }, 300000); // 5 minutes
    </script>

    <!-- Print Styles -->
    <style media="print">
        @page {
            margin: 1in;
        }
        
        .no-print {
            display: none !important;
        }
        
        body {
            background: white !important;
        }
        
        .bg-gray-50,
        .bg-gray-100 {
            background: white !important;
        }
        
        .shadow-sm,
        .shadow-lg {
            box-shadow: none !important;
        }
        
        .card-hover-effect:hover {
            transform: none !important;
        }
        
        table {
            page-break-inside: auto;
        }
        
        tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
        
        thead {
            display: table-header-group;
        }
        
        .chart-container {
            page-break-inside: avoid;
        }
    </style>
</body>
</html>