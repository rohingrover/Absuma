<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Set page title and subtitle for header component
// $page_title = 'Fleet Management Dashboard';
// $page_subtitle = date('l, F j, Y');

// Count vehicles for dashboard
function getAvailableVehicleCount() {
    global $pdo;
    return $pdo->query("SELECT COUNT(*) FROM vehicles WHERE current_status = 'available'")->fetchColumn();
}

function getOnTripVehicleCount() {
    global $pdo;
    return $pdo->query("SELECT COUNT(*) FROM vehicles WHERE current_status IN ('loaded', 'on_trip')")->fetchColumn();
}

function getMaintenanceVehicleCount() {
    global $pdo;
    return $pdo->query("SELECT COUNT(*) FROM vehicles WHERE current_status = 'maintenance'")->fetchColumn();
}

function getFinancedVehicleCount() {
    global $pdo;
    return $pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_financed = 1")->fetchColumn();
}

function getRecentVehicles($limit = 8) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT id, vehicle_number, driver_name, owner_name, make_model, manufacturing_year, gvw, current_status, created_at,
               CASE 
                   WHEN is_financed = 1 THEN 'Financed'
                   ELSE 'Own'
               END as financing_status
        FROM vehicles 
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getVehicleStatusSummary() {
    global $pdo;
    
    $query = "
        SELECT 
            current_status,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM vehicles)), 1) as percentage
        FROM vehicles 
        GROUP BY current_status
        ORDER BY count DESC
    ";
    
    return $pdo->query($query)->fetchAll();
}

function getFinancingSummary() {
    global $pdo;
    
    $query = "
        SELECT 
            COUNT(CASE WHEN v.is_financed = 1 THEN 1 END) as financed_count,
            COUNT(CASE WHEN v.is_financed = 0 THEN 1 END) as own_count,
            COALESCE(SUM(CASE WHEN v.is_financed = 1 THEN vf.emi_amount END), 0) as total_emi,
            COALESCE(AVG(CASE WHEN v.is_financed = 1 THEN vf.emi_amount END), 0) as avg_emi,
            COUNT(DISTINCT CASE WHEN v.is_financed = 1 THEN vf.bank_name END) as bank_count
        FROM vehicles v
        LEFT JOIN vehicle_financing vf ON v.id = vf.vehicle_id
    ";
    
    return $pdo->query($query)->fetch();
}

function getTopBanks() {
    global $pdo;
    
    $query = "
        SELECT 
            vf.bank_name,
            COUNT(*) as vehicle_count,
            SUM(vf.emi_amount) as total_emi,
            AVG(vf.emi_amount) as avg_emi
        FROM vehicles v
        JOIN vehicle_financing vf ON v.id = vf.vehicle_id
        WHERE v.is_financed = 1 AND vf.bank_name IS NOT NULL
        GROUP BY vf.bank_name
        ORDER BY vehicle_count DESC, total_emi DESC
        LIMIT 5
    ";
    
    return $pdo->query($query)->fetchAll();
}

function getFleetUtilization() {
    global $pdo;
    $result = $pdo->query("
        SELECT 
            ROUND((COUNT(CASE WHEN current_status IN ('loaded', 'on_trip') THEN 1 END) / COUNT(*)) * 100) as utilization
        FROM vehicles
    ")->fetchColumn();
    return $result ? $result : 0;
}

function getDocumentAlerts($limit = 5) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT 
            da.vehicle_number,
            da.document_type,
            da.expiry_date,
            da.days_remaining,
            CASE 
                WHEN da.days_remaining < 0 THEN 'expired'
                WHEN da.days_remaining <= 7 THEN 'critical'
                WHEN da.days_remaining <= 30 THEN 'warning'
                ELSE 'normal'
            END as alert_level
        FROM document_alerts da
        WHERE da.days_remaining <= 30
        ORDER BY da.days_remaining ASC, da.expiry_date ASC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// Get all dashboard data
$availableVehicles = getAvailableVehicleCount();
$onTripVehicles = getOnTripVehicleCount();
$maintenanceVehicles = getMaintenanceVehicleCount();
$financedVehicles = getFinancedVehicleCount();
$fleetUtilization = getFleetUtilization();
$statusSummary = getVehicleStatusSummary();
$financingSummary = getFinancingSummary();
$topBanks = getTopBanks();
$documentAlerts = getDocumentAlerts();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Absuma Logistics Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="notifications.js"></script>
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
                        'float': 'float 3s ease-in-out infinite',
                        'pulse-slow': 'pulse 3s infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0', transform: 'translateY(10px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-5px)' },
                        }
                    },
                    boxShadow: {
                        'soft': '0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025)',
                        'glow': '0 0 15px -3px rgba(229, 62, 62, 0.3)',
                        'glow-blue': '0 0 15px -3px rgba(99, 102, 241, 0.3)',
                    }
                }
            }
        }
    </script>
    <style>
        @layer utilities {
            .animation-delay-100 { animation-delay: 0.1s; }
            .animation-delay-200 { animation-delay: 0.2s; }
            .animation-delay-300 { animation-delay: 0.3s; }
            .animation-delay-400 { animation-delay: 0.4s; }
            
            .badge {
                @apply inline-block px-2.5 py-1 rounded-full text-xs font-semibold;
            }
            .badge-available {
                @apply bg-green-100 text-green-800;
            }
            .badge-loaded {
                @apply bg-blue-100 text-blue-800;
            }
            .badge-on_trip {
                @apply bg-amber-100 text-amber-800;
            }
            .badge-maintenance {
                @apply bg-red-100 text-red-800;
            }
            .badge-financed {
                @apply bg-purple-100 text-purple-800;
            }
            .badge-own {
                @apply bg-gray-100 text-gray-800;
            }
            .badge-expired {
                @apply bg-red-100 text-red-800;
            }
            .badge-critical {
                @apply bg-orange-100 text-orange-800;
            }
            .badge-warning {
                @apply bg-amber-100 text-amber-800;
            }
            
            .gradient-bg {
                background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            }
            
            .card-hover-effect {
                @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
            }

            .absuma-gradient {
                background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            }

            .stats-card {
                @apply bg-white rounded-xl shadow-soft p-6 border-l-4 transition-all duration-300 hover:shadow-glow-blue hover:-translate-y-1;
            }
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
                <aside class="w-full lg:w-64 flex-shrink-0">
                    <div class="bg-white rounded-xl shadow-soft p-4 sticky top-20 border border-white/20 backdrop-blur-sm bg-white/70">
                        <!-- Vehicle Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Vehicle Section</h3>
                            <nav class="space-y-1.5">
                                <a href="add_vehicle.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-dark hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Add Vehicle & Driver
                                </a>
                                <a href="manage_vehicles.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Vehicles
                                </a>
                                <a href="manage_drivers.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-users w-5 text-center text-absuma-red group-hover:text-absuma-red"></i> Manage Drivers
                                </a>
                            </nav>
                        </div>
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Vendors Section</h3>
                            <nav class="space-y-1">
                                <a href="vendor_registration.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red"></i> Register Vendor
                                </a>
                                <a href="manage_vendors.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all">
                                    <i class="fas fa-list w-5 text-center"></i> Manage Vendors
                                </a>
                                <a href="dashboard.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium absuma-gradient text-white rounded-lg transition-all">
                                    <i class="fas fa-tachometer-alt w-5 text-center"></i> Dashboard
                                </a>
                            </nav>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1">
                    <div class="space-y-6">
                        <!-- Welcome Section -->
                        <div class="bg-white rounded-xl shadow-soft p-6 border-l-4 border-absuma-red">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-800 mb-2">
                                        Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?>!
                                    </h2>
                                    <p class="text-gray-600">Here's your fleet overview for today</p>
                                </div>
                                <div class="hidden md:block">
                                    <div class="bg-red-50 p-4 rounded-lg">
                                        <i class="fas fa-chart-line text-3xl text-absuma-red"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Stats Grid with Absuma colors -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <!-- Available Vehicles Card -->
                            <div class="stats-card border-green-300 animate-fade-in animation-delay-100">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-green-200/70 flex items-center justify-center text-green-700 shadow-inner">
                                        <i class="fas fa-truck text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-green-800">Available Vehicles</p>
                                        <div class="flex items-end gap-2">
                                            <p class="text-2xl font-bold text-green-900" id="available-vehicles-count"><?= $availableVehicles ?></p>
                                            <p class="text-xs text-green-600/80" id="last-updated">Updated: <?= date('H:i:s') ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- On Trip Vehicles Card -->
                            <div class="stats-card border-amber-300 animate-fade-in animation-delay-200">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-amber-200/70 flex items-center justify-center text-amber-700 shadow-inner">
                                        <i class="fas fa-truck-moving text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-amber-800">On Trip/Loaded</p>
                                        <p class="text-2xl font-bold text-amber-900"><?= $onTripVehicles ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Maintenance Vehicles Card -->
                            <div class="stats-card border-red-300 animate-fade-in animation-delay-300">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-red-200/70 flex items-center justify-center text-red-700 shadow-inner">
                                        <i class="fas fa-tools text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-red-800">Under Maintenance</p>
                                        <p class="text-2xl font-bold text-red-900"><?= $maintenanceVehicles ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Fleet Utilization Card -->
                            <div class="stats-card border-absuma-red animate-fade-in animation-delay-400">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-red-200/70 flex items-center justify-center text-absuma-red shadow-inner">
                                        <i class="fas fa-chart-line text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-red-800">Fleet Utilization</p>
                                        <p class="text-2xl font-bold text-red-900"><?= $fleetUtilization ?>%</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Vehicle Status Summary -->
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden animate-fade-in hover:shadow-glow transition-all duration-300 border border-white/20 backdrop-blur-sm bg-white/70">
                            <div class="px-6 py-4 border-b border-gray-200/50 bg-gradient-to-r from-red-50 to-white">
                                <h2 class="text-lg font-semibold text-dark flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center text-absuma-red">
                                        <i class="fas fa-chart-pie"></i>
                                    </div>
                                    <span>Vehicle Status Overview</span>
                                </h2>
                            </div>
                            <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <?php foreach ($statusSummary as $status): ?>
                                <div class="bg-white border border-gray-200/30 rounded-xl p-4 hover:shadow-md transition-all card-hover-effect">
                                    <div class="flex items-center justify-between mb-2">
                                        <h3 class="text-sm font-medium text-gray-700"><?= ucfirst(str_replace('_', ' ', $status['current_status'])) ?></h3>
                                        <span class="badge badge-<?= strtolower($status['current_status']) ?>"><?= $status['count'] ?></span>
                                    </div>
                                    <div class="mt-2">
                                        <div class="flex justify-between text-xs font-medium text-gray-500 mb-1">
                                            <span>Percentage</span>
                                            <span><?= $status['percentage'] ?>%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-1.5">
                                            <div class="bg-absuma-red h-1.5 rounded-full" style="width: <?= $status['percentage'] ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Financing Summary -->
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden animate-fade-in hover:shadow-glow transition-all duration-300 border border-white/20 backdrop-blur-sm bg-white/70">
                            <div class="px-6 py-4 border-b border-gray-200/50 bg-gradient-to-r from-green-50 to-white">
                                <h2 class="text-lg font-semibold text-dark flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center text-green-700">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>
                                    <span>Financing Overview</span>
                                </h2>
                            </div>
                            <div class="p-6">
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-purple-600"><?= $financingSummary['financed_count'] ?></div>
                                        <div class="text-sm text-gray-600">Financed Vehicles</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-blue-600"><?= $financingSummary['own_count'] ?></div>
                                        <div class="text-sm text-gray-600">Own Vehicles</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-green-600">₹<?= number_format($financingSummary['total_emi'], 0) ?></div>
                                        <div class="text-sm text-gray-600">Total Monthly EMI</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-orange-600">₹<?= number_format($financingSummary['avg_emi'], 0) ?></div>
                                        <div class="text-sm text-gray-600">Average EMI</div>
                                    </div>
                                </div>

                                <?php if (!empty($topBanks)): ?>
                                <div class="border-t border-gray-200 pt-4">
                                    <h3 class="text-md font-medium text-gray-900 mb-4">Top Financing Banks</h3>
                                    <div class="space-y-3">
                                        <?php foreach ($topBanks as $bank): ?>
                                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-red-50 transition-colors">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center text-absuma-red">
                                                    <i class="fas fa-university text-sm"></i>
                                                </div>
                                                <div>
                                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($bank['bank_name']) ?></div>
                                                    <div class="text-sm text-gray-500"><?= $bank['vehicle_count'] ?> vehicles</div>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div class="font-medium text-gray-900">₹<?= number_format($bank['total_emi'], 0) ?></div>
                                                <div class="text-sm text-gray-500">Total EMI</div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Document Alerts -->
                        <?php if (!empty($documentAlerts)): ?>
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden animate-fade-in hover:shadow-glow transition-all duration-300 border border-white/20 backdrop-blur-sm bg-white/70">
                            <div class="px-6 py-4 border-b border-gray-200/50 bg-gradient-to-r from-red-50 to-white">
                                <h2 class="text-lg font-semibold text-dark flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center text-red-700">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <span>Document Alerts</span>
                                    <span class="badge badge-warning"><?= count($documentAlerts) ?></span>
                                </h2>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200/50">
                                    <thead class="bg-gray-50/50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Vehicle</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Document</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Expiry Date</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200/30">
                                        <?php foreach ($documentAlerts as $alert): ?>
                                        <tr class="hover:bg-red-50/50 transition-colors">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center gap-2">
                                                    <i class="fas fa-truck text-absuma-red"></i>
                                                    <span class="font-medium text-dark"><?= htmlspecialchars($alert['vehicle_number']) ?></span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-dark">
                                                <?= htmlspecialchars($alert['document_type']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-dark">
                                                <?= date('M d, Y', strtotime($alert['expiry_date'])) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($alert['days_remaining'] < 0): ?>
                                                    <span class="badge badge-expired">Expired (<?= abs($alert['days_remaining']) ?> days ago)</span>
                                                <?php elseif ($alert['days_remaining'] <= 7): ?>
                                                    <span class="badge badge-critical">Critical (<?= $alert['days_remaining'] ?> days)</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Expires in <?= $alert['days_remaining'] ?> days</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Recent Vehicles -->
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden animate-fade-in hover:shadow-glow transition-all duration-300 border border-white/20 backdrop-blur-sm bg-white/70">
                            <div class="px-6 py-4 border-b border-gray-200/50 flex justify-between items-center bg-gradient-to-r from-blue-50 to-white">
                                <h2 class="text-lg font-semibold text-dark flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <span>Recently Added Vehicles</span>
                                </h2>
                                <a href="manage_vehicles.php" class="text-sm font-medium text-absuma-red hover:text-absuma-red-dark flex items-center gap-1 px-3 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 transition-all">
                                    View All <i class="fas fa-arrow-right text-xs mt-0.5"></i>
                                </a>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200/50">
                                    <thead class="bg-gray-50/50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Vehicle</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Driver/Owner</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Vehicle Info</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Financing</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Added</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200/30">
                                        <?php 
                                        $recentVehicles = getRecentVehicles(8); 
                                        foreach ($recentVehicles as $vehicle): ?>
                                        <tr class="hover:bg-red-50/50 transition-colors">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8 rounded-full bg-red-100 flex items-center justify-center text-absuma-red">
                                                        <i class="fas fa-truck text-sm"></i>
                                                    </div>
                                                    <div class="ml-3">
                                                        <div class="font-medium text-dark"><?= htmlspecialchars($vehicle['vehicle_number']) ?></div>
                                                        <?php if ($vehicle['make_model']): ?>
                                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($vehicle['make_model']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-dark font-medium">
                                                    <i class="fas fa-user text-gray-400 mr-1"></i>
                                                    <?= htmlspecialchars($vehicle['driver_name']) ?>
                                                </div>
                                                <?php if ($vehicle['owner_name'] && $vehicle['owner_name'] !== $vehicle['driver_name']): ?>
                                                    <div class="text-xs text-gray-500">
                                                        <i class="fas fa-crown text-yellow-500 mr-1"></i>
                                                        <?= htmlspecialchars($vehicle['owner_name']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($vehicle['manufacturing_year']): ?>
                                                    <div class="text-sm text-dark">Year: <?= $vehicle['manufacturing_year'] ?></div>
                                                <?php endif; ?>
                                                <?php if ($vehicle['gvw']): ?>
                                                    <div class="text-xs text-gray-500">GVW: <?= number_format($vehicle['gvw'], 0) ?> kg</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="badge badge-<?= strtolower($vehicle['current_status']) ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $vehicle['current_status'])) ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="badge badge-<?= strtolower(str_replace(' ', '-', $vehicle['financing_status'])) ?>">
                                                    <?= $vehicle['financing_status'] ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-dark">
                                                <?= date('M d, Y', strtotime($vehicle['created_at'])) ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh every 30 seconds
        setInterval(function() {
            fetch('get_dashboard_stats.php')
                .then(response => response.json())
                .then(data => {
                    if (data.available !== undefined) {
                        document.getElementById('available-vehicles-count').textContent = data.available;
                        document.getElementById('last-updated').textContent = 'Updated: ' + new Date().toLocaleTimeString();
                        
                        // Add pulse animation to show update
                        const countElement = document.getElementById('available-vehicles-count');
                        countElement.classList.add('animate-pulse');
                        setTimeout(() => {
                            countElement.classList.remove('animate-pulse');
                        }, 1000);
                    }
                })
                .catch(error => {
                    console.log('Auto-refresh failed:', error);
                });
        }, 30000);
        
        function updateCurrentTime() {
            const now = new Date();
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = now.toLocaleTimeString();
            }
        }
        setInterval(updateCurrentTime, 1000);
    </script>
</body>
</html>