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
    <title>Dashboard - Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'teal-600': '#0d9488',
                        'teal-700': '#0f766e',
                        'teal-500': '#14b8a6',
                        'teal-50': '#f0fdfa',
                        'teal-100': '#ccfbf1',
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #f0fdfa 0%, #e6fffa 100%);
        }
        
        .shadow-soft {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .card-hover-effect {
            transition: all 0.3s ease;
        }
        
        .card-hover-effect:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out forwards;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .progress-ring {
            transition: stroke-dasharray 0.8s ease-in-out;
        }
        
        .map-container {
            background: linear-gradient(135deg, #ccfbf1 0%, #99f6e4 50%, #5eead4 100%);
            position: relative;
            overflow: hidden;
        }
        
        .vehicle-marker {
            position: absolute;
            width: 12px;
            height: 12px;
            background: #f59e0b;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .revenue-bar {
            transition: height 0.8s ease-out;
        }
        
        .nav-active {
            background: white;
            color: #0d9488;
        }
        
        .nav-item {
            transition: all 0.2s ease;
        }
        
        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen flex">
        <!-- Sidebar Navigation -->
      <?php include 'sidebar_navigation.php' ?>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
                    <div class="flex items-center space-x-4">
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input
                                type="text"
                                placeholder="Search"
                                class="pl-10 pr-10 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm"
                            />
                            <i class="fas fa-microphone absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm cursor-pointer hover:text-teal-600"></i>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <main class="flex-1 p-6 overflow-auto">
                <!-- Top Cards Row -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                    
                    <!-- Total Vehicles Card -->
                    <div class="bg-white rounded-lg shadow-soft p-6 card-hover-effect animate-fade-in">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Total Vehicles</h3>
                        <div class="flex items-center justify-center mb-4">
                            <div class="relative w-32 h-32">
                                <!-- Circular Progress -->
                                <svg class="w-32 h-32 transform -rotate-90" viewBox="0 0 128 128">
                                    <!-- Background circle -->
                                    <circle
                                        cx="64"
                                        cy="64"
                                        r="56"
                                        stroke="#e5e7eb"
                                        stroke-width="8"
                                        fill="none"
                                    />
                                    <!-- Progress circle -->
                                    <circle
                                        cx="64"
                                        cy="64"
                                        r="56"
                                        stroke="#14b8a6"
                                        stroke-width="8"
                                        fill="none"
                                        stroke-dasharray="351.86"
                                        stroke-dashoffset="35.19"
                                        class="progress-ring"
                                        stroke-linecap="round"
                                    />
                                </svg>
                                <div class="absolute inset-0 flex flex-col items-center justify-center">
                                    <span class="text-2xl font-bold text-gray-900" id="total-vehicles">100</span>
                                    <span class="text-sm text-gray-500">Vehicles</span>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2">
                                    <div class="w-3 h-3 bg-teal-500 rounded-full"></div>
                                    <span class="text-sm text-gray-600">On Route</span>
                                </div>
                                <span class="font-medium" id="on-route">85</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2">
                                    <div class="w-3 h-3 bg-gray-300 rounded-full"></div>
                                    <span class="text-sm text-gray-600">Available</span>
                                </div>
                                <span class="font-medium" id="available">10</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2">
                                    <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                                    <span class="text-sm text-gray-600">Out of Service</span>
                                </div>
                                <span class="font-medium" id="out-of-service">5</span>
                            </div>
                        </div>
                    </div>

                    <!-- Trips Card -->
                    <div class="bg-white rounded-lg shadow-soft p-6 card-hover-effect animate-fade-in" style="animation-delay: 0.1s">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Trips</h3>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <div class="flex items-center space-x-3">
                                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                                    <span class="text-sm text-gray-600">Live Trips</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="font-medium text-lg" id="live-trips">68</span>
                                    <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
                                </div>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <div class="flex items-center space-x-3">
                                    <div class="w-3 h-3 bg-yellow-500 rounded-full"></div>
                                    <span class="text-sm text-gray-600">Scheduled Trips</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="font-medium text-lg" id="scheduled-trips">12</span>
                                    <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
                                </div>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <div class="flex items-center space-x-3">
                                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                                    <span class="text-sm text-gray-600">Completed Trips</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="font-medium text-lg" id="completed-trips">13</span>
                                    <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
                                </div>
                            </div>
                            <div class="flex items-center justify-between py-2">
                                <div class="flex items-center space-x-3">
                                    <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                                    <span class="text-sm text-gray-600">Late Trips</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="font-medium text-lg" id="late-trips">7</span>
                                    <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Vehicle Condition Card -->
                    <div class="bg-white rounded-lg shadow-soft p-6 card-hover-effect animate-fade-in" style="animation-delay: 0.2s">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Vehicles Condition</h3>
                        <div class="grid grid-cols-3 gap-4">
                            <!-- Good Condition -->
                            <div class="text-center">
                                <div class="relative w-16 h-16 mx-auto mb-2">
                                    <svg class="w-16 h-16 transform -rotate-90" viewBox="0 0 64 64">
                                        <circle cx="32" cy="32" r="28" stroke="#e5e7eb" stroke-width="4" fill="none"/>
                                        <circle cx="32" cy="32" r="28" stroke="#10b981" stroke-width="4" fill="none"
                                                stroke-dasharray="175.93" stroke-dashoffset="26.39" class="progress-ring"/>
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <span class="text-xs font-bold" id="good-vehicles">85</span>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-600 font-medium">Good</div>
                                <div class="text-xs text-gray-500">Vehicles</div>
                            </div>
                            
                            <!-- Satisfactory Condition -->
                            <div class="text-center">
                                <div class="relative w-16 h-16 mx-auto mb-2">
                                    <svg class="w-16 h-16 transform -rotate-90" viewBox="0 0 64 64">
                                        <circle cx="32" cy="32" r="28" stroke="#e5e7eb" stroke-width="4" fill="none"/>
                                        <circle cx="32" cy="32" r="28" stroke="#f59e0b" stroke-width="4" fill="none"
                                                stroke-dasharray="175.93" stroke-dashoffset="158.34" class="progress-ring"/>
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <span class="text-xs font-bold" id="satisfactory-vehicles">10</span>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-600 font-medium">Satisfactory</div>
                                <div class="text-xs text-gray-500">Vehicles</div>
                            </div>

                            <!-- Critical Condition -->
                            <div class="text-center">
                                <div class="relative w-16 h-16 mx-auto mb-2">
                                    <svg class="w-16 h-16 transform -rotate-90" viewBox="0 0 64 64">
                                        <circle cx="32" cy="32" r="28" stroke="#e5e7eb" stroke-width="4" fill="none"/>
                                        <circle cx="32" cy="32" r="28" stroke="#ef4444" stroke-width="4" fill="none"
                                                stroke-dasharray="175.93" stroke-dashoffset="167.13" class="progress-ring"/>
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <span class="text-xs font-bold" id="critical-vehicles">5</span>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-600 font-medium">Critical</div>
                                <div class="text-xs text-gray-500">Vehicles</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom Section -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <!-- Live Tracking Map -->
                    <div class="lg:col-span-2 bg-white rounded-lg shadow-soft p-6 card-hover-effect animate-fade-in" style="animation-delay: 0.3s">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Live Tracking</h3>
                        <div class="relative h-64 map-container rounded-lg overflow-hidden">
                            <!-- City Label -->
                            <div class="absolute top-4 left-4 bg-white px-3 py-1 rounded shadow text-sm font-medium z-10">
                                Chennai
                            </div>
                            
                            <!-- Road Lines -->
                            <div class="absolute top-8 left-0 w-full h-0.5 bg-gray-400 opacity-30"></div>
                            <div class="absolute top-16 left-0 w-full h-0.5 bg-gray-400 opacity-30"></div>
                            <div class="absolute bottom-16 left-0 w-full h-0.5 bg-gray-400 opacity-30"></div>
                            <div class="absolute top-0 left-1/4 w-0.5 h-full bg-gray-400 opacity-30"></div>
                            <div class="absolute top-0 right-1/4 w-0.5 h-full bg-gray-400 opacity-30"></div>
                            
                            <!-- Vehicle Markers -->
                            <div class="vehicle-marker" style="top: 12%; left: 20%;"></div>
                            <div class="vehicle-marker" style="top: 25%; right: 32%;"></div>
                            <div class="vehicle-marker" style="bottom: 30%; left: 32%;"></div>
                            <div class="vehicle-marker" style="bottom: 20%; right: 20%;"></div>
                            <div class="vehicle-marker" style="top: 45%; left: 45%;"></div>
                            <div class="vehicle-marker" style="top: 60%; right: 40%;"></div>
                            <div class="vehicle-marker" style="bottom: 35%; left: 60%;"></div>
                        </div>
                    </div>

                    <!-- Revenue Chart -->
                    <div class="bg-white rounded-lg shadow-soft p-6 card-hover-effect animate-fade-in" style="animation-delay: 0.4s">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Revenue</h3>
                        <div class="h-48 flex items-end justify-between space-x-2 mb-4" id="revenue-chart">
                            <!-- Revenue bars will be inserted here by JavaScript -->
                        </div>
                        <div class="text-center pt-4 border-t border-gray-100">
                            <div class="text-2xl font-bold text-gray-900">₹2,450K</div>
                            <div class="text-sm text-gray-600">Total Revenue</div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Dashboard Data Management
        class DashboardManager {
            constructor() {
                this.data = {
                    vehicles: {
                        total: 100,
                        onRoute: 85,
                        available: 10,
                        outOfService: 5
                    },
                    trips: {
                        live: 68,
                        scheduled: 12,
                        completed: 13,
                        late: 7
                    },
                    condition: {
                        good: 85,
                        satisfactory: 10,
                        critical: 5
                    },
                    revenue: [
                        { month: 'Jan', value: 500 },
                        { month: 'Feb', value: 400 },
                        { month: 'Mar', value: 250 },
                        { month: 'Apr', value: 450 },
                        { month: 'May', value: 500 },
                        { month: 'Jun', value: 350 }
                    ]
                };
                this.init();
            }

            init() {
                this.renderRevenueChart();
                this.updateCircularProgress();
                this.setupRealTimeUpdates();
            }

            renderRevenueChart() {
                const chartContainer = document.getElementById('revenue-chart');
                const maxValue = Math.max(...this.data.revenue.map(item => item.value));
                
                chartContainer.innerHTML = '';
                
                this.data.revenue.forEach((item, index) => {
                    const percentage = (item.value / maxValue) * 100;
                    const bar = document.createElement('div');
                    bar.className = 'flex flex-col items-center flex-1';
                    bar.innerHTML = `
                        <div class="w-full bg-teal-500 rounded-t revenue-bar hover:bg-teal-600 transition-colors cursor-pointer" 
                             style="height: ${percentage * 1.6}px; min-height: 4px;"
                             title="${item.month}: ₹${item.value}K">
                        </div>
                        <span class="text-xs text-gray-600 mt-2">${item.month}</span>
                    `;
                    chartContainer.appendChild(bar);
                });
            }

            updateCircularProgress() {
                const totalVehicles = this.data.vehicles.total;
                const onRoute = this.data.vehicles.onRoute;
                const circumference = 2 * Math.PI * 56; // radius = 56
                const progress = (onRoute / totalVehicles) * circumference;
                const offset = circumference - progress;
                
                const progressCircle = document.querySelector('.progress-ring');
                if (progressCircle) {
                    progressCircle.style.strokeDashoffset = offset;
                }
            }

            setupRealTimeUpdates() {
                // Simulate real-time data updates
                setInterval(() => {
                    this.simulateDataUpdate();
                }, 30000); // Update every 30 seconds
            }

            simulateDataUpdate() {
                // Add small random variations to simulate real-time data
                const variation = () => Math.floor(Math.random() * 3) - 1; // -1, 0, or 1
                
                this.data.trips.live = Math.max(60, Math.min(75, this.data.trips.live + variation()));
                this.data.trips.late = Math.max(5, Math.min(10, this.data.trips.late + variation()));
                
                // Update display
                document.getElementById('live-trips').textContent = this.data.trips.live;
                document.getElementById('late-trips').textContent = this.data.trips.late;
            }

            // Method to integrate with PHP data
            updateFromServer(serverData) {
                if (serverData.vehicles) {
                    this.data.vehicles = { ...this.data.vehicles, ...serverData.vehicles };
                    this.updateVehicleDisplay();
                }
                if (serverData.trips) {
                    this.data.trips = { ...this.data.trips, ...serverData.trips };
                    this.updateTripsDisplay();
                }
                if (serverData.revenue) {
                    this.data.revenue = serverData.revenue;
                    this.renderRevenueChart();
                }
            }

            updateVehicleDisplay() {
                document.getElementById('total-vehicles').textContent = this.data.vehicles.total;
                document.getElementById('on-route').textContent = this.data.vehicles.onRoute;
                document.getElementById('available').textContent = this.data.vehicles.available;
                document.getElementById('out-of-service').textContent = this.data.vehicles.outOfService;
                this.updateCircularProgress();
            }

            updateTripsDisplay() {
                document.getElementById('live-trips').textContent = this.data.trips.live;
                document.getElementById('scheduled-trips').textContent = this.data.trips.scheduled;
                document.getElementById('completed-trips').textContent = this.data.trips.completed;
                document.getElementById('late-trips').textContent = this.data.trips.late;
            }
        }

        // Navigation Management
        class NavigationManager {
            constructor() {
                this.setupNavigation();
            }

            setupNavigation() {
                const navItems = document.querySelectorAll('.nav-item');
                navItems.forEach(item => {
                    item.addEventListener('click', (e) => {
                        if (!item.classList.contains('nav-active')) {
                            this.setActiveNav(item);
                        }
                    });
                });
            }

            setActiveNav(activeItem) {
                const navItems = document.querySelectorAll('.nav-item');
                navItems.forEach(item => {
                    item.classList.remove('nav-active');
                    item.classList.add('text-teal-100');
                });
                
                activeItem.classList.add('nav-active');
                activeItem.classList.remove('text-teal-100');
            }
        }

        // Search Functionality
        class SearchManager {
            constructor() {
                this.setupSearch();
            }

            setupSearch() {
                const searchInput = document.querySelector('input[placeholder="Search"]');
                const micIcon = document.querySelector('.fa-microphone');
                
                searchInput.addEventListener('input', (e) => {
                    this.handleSearch(e.target.value);
                });

                micIcon.addEventListener('click', () => {
                    this.handleVoiceSearch();
                });
            }

            handleSearch(query) {
                if (query.length > 2) {
                    console.log('Searching for:', query);
                    // Implement search functionality here
                    // You can integrate this with your PHP backend
                }
            }

            handleVoiceSearch() {
                if ('webkitSpeechRecognition' in window) {
                    const recognition = new webkitSpeechRecognition();
                    recognition.lang = 'en-US';
                    recognition.onresult = (event) => {
                        const result = event.results[0][0].transcript;
                        document.querySelector('input[placeholder="Search"]').value = result;
                        this.handleSearch(result);
                    };
                    recognition.start();
                } else {
                    alert('Voice search not supported in this browser');
                }
            }
        }

        // Initialize Dashboard
        document.addEventListener('DOMContentLoaded', () => {
            const dashboard = new DashboardManager();
            const navigation = new NavigationManager();
            const search = new SearchManager();

            // Example of how to integrate with PHP data
            // You can call this function with data from your PHP backend
            window.updateDashboard = function(phpData) {
                dashboard.updateFromServer(phpData);
            };

            console.log('Dashboard initialized successfully');
        });

        // PHP Integration Helper Functions
        function fetchDashboardData() {
            // Example AJAX call to get updated data from your PHP backend
            fetch('dashboard_api.php')
                .then(response => response.json())
                .then(data => {
                    window.updateDashboard(data);
                })
                .catch(error => {
                    console.error('Error fetching dashboard data:', error);
                });
        }

        // Auto-refresh data every 5 minutes
        setInterval(fetchDashboardData, 300000);
    </script>
</body>
</html>