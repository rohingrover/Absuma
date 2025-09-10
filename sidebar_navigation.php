<?php
// sidebar_navigation.php - Reusable sidebar component with role-based access control and collapsible sections

if (!isset($_SESSION['role'])) {
    header('Location: index.php');
    exit();
}

// Calculate relative path to project root
$php_self = $_SERVER['PHP_SELF'];
$slash_count = substr_count($php_self, '/');
$base_slashes = 1; // Assuming structure like /absuma/page.php or /absuma/sub/page.php
$levels = max(0, $slash_count - $base_slashes);
$relative_to_root = str_repeat('../', $levels);

// Normalize current page path robustly (supports root or subfolder deploys)
// Examples:
//  - /booking/manage.php            => /booking/manage.php
//  - /absuma/booking/manage.php     => /booking/manage.php
//  - /dashboard.php                 => /dashboard.php
$script_path = $_SERVER['SCRIPT_NAME'] ?? $php_self;
$normalized_current = $script_path;
if (preg_match('#^/(?:[^/]+/)?([^/]+/[^/]+)$#', $script_path, $m)) {
    $normalized_current = '/' . $m[1];
}

// Function to check if user has permission for a specific role level
if (!function_exists('hasRoleAccess')) {
    function hasRoleAccess($required_role, $user_role, $role_hierarchy) {
        return $role_hierarchy[$user_role] >= $role_hierarchy[$required_role];
    }
}

// Function to check if user can approve changes
if (!function_exists('canApprove')) {
    function canApprove($user_role) {
        return in_array($user_role, ['manager1', 'manager2', 'admin', 'superadmin']);
    }
}

// Function to determine which section should be active based on current page
if (!function_exists('getActiveSection')) {
    function getActiveSection($normalized_current) {
        $page_sections = [
            // Dashboard
            '/dashboard.php' => 'dashboard',
            
            // User Profile
            '/user_profile.php' => 'profile',
            
            // Vehicle Management
            '/vehicles/manage.php' => 'vehicle',
            '/vehicles/add.php' => 'vehicle',
            '/vehicles/manage_drivers.php' => 'vehicle',
            
            // Approvals
            '/approvals/pending_approvals.php' => 'approval',
            
            // Trip Management
            '/trips/create_trip.php' => 'trip',
            '/trips/manage_trips.php' => 'trip',
            '/trips/trip_reports.php' => 'trip',
            
            // Booking Management
            '/booking/create.php' => 'booking',
            '/booking/manage.php' => 'booking',
            '/booking/edit.php' => 'booking',
            '/booking/view.php' => 'booking',
            
            // Client Management
            '/clients/manage_clients.php' => 'client',
            '/clients/add_client.php' => 'client',
            
            // Vendor Management
            '/vendors/manage_vendors.php' => 'vendor',
            '/vendors/vendor_registration.php' => 'vendor',
            
            // Reports
            '/reports/client_reports.php' => 'reports',
            '/reports/vehicle_reports.php' => 'reports',
            
            // Administration
            '/admin/manage_users.php' => 'admin',
            '/admin/system_settings.php' => 'admin',
            '/admin/audit_logs.php' => 'admin',
            
            // Communication
            '/communication/messages.php' => 'communication',
            '/communication/service_requests.php' => 'communication'
        ];
        
        return $page_sections[$normalized_current] ?? 'dashboard';
    }
}

$active_section = getActiveSection($normalized_current);
$user_role = $_SESSION['role'];
$user_name = $_SESSION['full_name'] ?? 'User';

// Define role hierarchy (higher number = more permissions)
$role_hierarchy = [
    'staff' => 1,
    'l1_supervisor' => 2,
    'l2_supervisor' => 3,
    'manager1' => 4,
    'manager2' => 4,
    'admin' => 5,
    'superadmin' => 6
];

// Get pending approvals count for managers
$pending_approvals = 0;
if (canApprove($user_role)) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicle_change_requests WHERE status = 'pending'");
        $stmt->execute();
        $pending_approvals = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $pending_approvals = 0;
    }
}
?>

<aside class="w-64 bg-teal-600 text-white flex flex-col shadow-lg">
    <!-- Profile Section -->
    <div class="p-6 border-b border-teal-500">
        <div class="flex items-center space-x-3">
            <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center">
                <i class="fas fa-user-tie text-teal-600 text-lg"></i>
            </div>
            <div class="flex-1">
                <h2 class="text-lg font-semibold"><?= htmlspecialchars($user_name) ?></h2>
                <p class="text-teal-200 text-sm capitalize"><?= str_replace('_', ' ', $user_role) ?></p>
            </div>
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="flex-1 px-4 py-6 overflow-y-auto">
        <div class="space-y-2">
            <!-- Dashboard (accessible to all) -->
            <a href="<?= $relative_to_root ?>dashboard.php" class="nav-item <?= $normalized_current == '/dashboard.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium">
                <i class="fas fa-tachometer-alt w-5"></i>
                <span>Dashboard</span>
            </a>

            <!-- Vehicle Section (L2_Supervisor and above) -->
            <?php if (hasRoleAccess('l2_supervisor', $user_role, $role_hierarchy)): ?>
            <div class="mt-6">
                <button class="section-toggle w-full flex items-center justify-between px-4 py-2 text-xs font-semibold text-teal-300 uppercase tracking-wider hover:text-white transition-colors" 
                        data-section="vehicle">
                    <span>Vehicles</span>
                    <i class="fas fa-chevron-down transform transition-transform <?= $active_section == 'vehicle' ? 'rotate-180' : '' ?>"></i>
                </button>
                <div class="section-content <?= $active_section != 'vehicle' ? 'hidden' : '' ?>" data-section="vehicle">
                    <a href="<?= $relative_to_root ?>vehicles/manage.php" class="nav-item <?= $normalized_current == '/vehicles/manage.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-truck w-5"></i>
                        <span>Manage Vehicles</span>
                        <?php if ($user_role == 'l2_supervisor'): ?>
                            <i class="fas fa-info-circle text-yellow-300 text-xs ml-1" title="Requires approval"></i>
                        <?php endif; ?>
                    </a>
                    <a href="<?= $relative_to_root ?>vehicles/add.php" class="nav-item <?= $normalized_current == '/vehicles/add.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-plus w-5"></i>
                        <span>Add Vehicle</span>
                        <?php if ($user_role == 'l2_supervisor'): ?>
                            <i class="fas fa-info-circle text-yellow-300 text-xs ml-1" title="Requires approval"></i>
                        <?php endif; ?>
                    </a>
                    <a href="<?= $relative_to_root ?>vehicles/manage_drivers.php" class="nav-item <?= $normalized_current == '/vehicles/manage_drivers.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-users w-5"></i>
                        <span>Drivers</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Approval Section (Managers and above) -->
            <?php if (canApprove($user_role)): ?>
            <div class="mt-6">
                <button class="section-toggle w-full flex items-center justify-between px-4 py-2 text-xs font-semibold text-teal-300 uppercase tracking-wider hover:text-white transition-colors" 
                        data-section="approval">
                    <span>Approvals</span>
                    <i class="fas fa-chevron-down transform transition-transform <?= $active_section == 'approval' ? 'rotate-180' : '' ?>"></i>
                </button>
                <div class="section-content <?= $active_section != 'approval' ? 'hidden' : '' ?>" data-section="approval">
                    <a href="<?= $relative_to_root ?>approvals/pending_approvals.php" class="nav-item <?= $normalized_current == '/approvals/pending_approvals.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-clipboard-check w-5"></i>
                        <span>Pending Approvals</span>
                        <?php if ($pending_approvals > 0): ?>
                            <span class="bg-red-500 text-white text-xs rounded-full px-2 py-1 ml-auto"><?= $pending_approvals ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Trip Management (Manager1, Manager2 and above) -->
            <?php if (hasRoleAccess('manager1', $user_role, $role_hierarchy) || $user_role == 'manager2'): ?>
            <div class="mt-6">
                <button class="section-toggle w-full flex items-center justify-between px-4 py-2 text-xs font-semibold text-teal-300 uppercase tracking-wider hover:text-white transition-colors" 
                        data-section="trip">
                    <span>Trips</span>
                    <i class="fas fa-chevron-down transform transition-transform <?= $active_section == 'trip' ? 'rotate-180' : '' ?>"></i>
                </button>
                <div class="section-content <?= $active_section != 'trip' ? 'hidden' : '' ?>" data-section="trip">
                    <a href="<?= $relative_to_root ?>trips/create_trip.php" class="nav-item <?= $normalized_current == '/trips/create_trip.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-plus w-5"></i>
                        <span>Create Trip</span>
                    </a>
                    <a href="<?= $relative_to_root ?>trips/manage_trips.php" class="nav-item <?= $normalized_current == '/trips/manage_trips.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-route w-5"></i>
                        <span>Manage Trips</span>
                    </a>
                    <a href="<?= $relative_to_root ?>trips/trip_reports.php" class="nav-item <?= $normalized_current == '/trips/trip_reports.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-chart-line w-5"></i>
                        <span>Trip Reports</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Booking Section (Manager1 and above) -->
            <?php if (hasRoleAccess('l2_supervisor', $user_role, $role_hierarchy)): ?>
            <div class="mt-6">
                <button class="section-toggle w-full flex items-center justify-between px-4 py-2 text-xs font-semibold text-teal-300 uppercase tracking-wider hover:text-white transition-colors" 
                        data-section="booking">
                    <span>Bookings</span>
                    <i class="fas fa-chevron-down transform transition-transform <?= $active_section == 'booking' ? 'rotate-180' : '' ?>"></i>
                </button>
                <div class="section-content <?= $active_section != 'booking' ? 'hidden' : '' ?>" data-section="booking">
                    <a href="<?= $relative_to_root ?>booking/create.php" class="nav-item <?= $normalized_current == '/booking/create.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-calendar-plus w-5"></i>
                        <span>Create Booking</span>
                    </a>
                    <a href="<?= $relative_to_root ?>booking/manage.php" class="nav-item <?= in_array($normalized_current, ['/booking/manage.php','/booking/edit.php','/booking/view.php']) ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-calendar-alt w-5"></i>
                        <span>Manage Bookings</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Client Management (L2_Supervisor and above) -->
            <?php if (hasRoleAccess('l2_supervisor', $user_role, $role_hierarchy)): ?>
            <div class="mt-6">
                <button class="section-toggle w-full flex items-center justify-between px-4 py-2 text-xs font-semibold text-teal-300 uppercase tracking-wider hover:text-white transition-colors" 
                        data-section="client">
                    <span>Clients</span>
                    <i class="fas fa-chevron-down transform transition-transform <?= $active_section == 'client' ? 'rotate-180' : '' ?>"></i>
                </button>
                <div class="section-content <?= $active_section != 'client' ? 'hidden' : '' ?>" data-section="client">
                    <a href="<?= $relative_to_root ?>clients/manage_clients.php" class="nav-item <?= $normalized_current == '/clients/manage_clients.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-building w-5"></i>
                        <span>Manage Clients</span>
                    </a>
                    <a href="<?= $relative_to_root ?>clients/add_client.php" class="nav-item <?= $normalized_current == '/clients/add_client.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-user-plus w-5"></i>
                        <span>Add Client</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Vendor Management (L2_Supervisor and above) -->
            <?php if (hasRoleAccess('l2_supervisor', $user_role, $role_hierarchy)): ?>
            <div class="mt-6">
                <button class="section-toggle w-full flex items-center justify-between px-4 py-2 text-xs font-semibold text-teal-300 uppercase tracking-wider hover:text-white transition-colors" 
                        data-section="vendor">
                    <span>Vendors</span>
                    <i class="fas fa-chevron-down transform transition-transform <?= $active_section == 'vendor' ? 'rotate-180' : '' ?>"></i>
                </button>
                <div class="section-content <?= $active_section != 'vendor' ? 'hidden' : '' ?>" data-section="vendor">
                    <a href="<?= $relative_to_root ?>vendors/manage_vendors.php" class="nav-item <?= $normalized_current == '/vendors/manage_vendors.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-handshake w-5"></i>
                        <span>Manage Vendors</span>
                    </a>
                    <a href="<?= $relative_to_root ?>vendors/vendor_registration.php" class="nav-item <?= $normalized_current == '/vendors/vendor_registration.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-plus-circle w-5"></i>
                        <span>Add Vendor</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reports Section (Manager and above) -->
            <?php if (hasRoleAccess('manager1', $user_role, $role_hierarchy) || $user_role == 'manager2'): ?>
            <div class="mt-6">
                <button class="section-toggle w-full flex items-center justify-between px-4 py-2 text-xs font-semibold text-teal-300 uppercase tracking-wider hover:text-white transition-colors" 
                        data-section="reports">
                    <span>Reports</span>
                    <i class="fas fa-chevron-down transform transition-transform <?= $active_section == 'reports' ? 'rotate-180' : '' ?>"></i>
                </button>
                <div class="section-content <?= $active_section != 'reports' ? 'hidden' : '' ?>" data-section="reports">
                    <a href="<?= $relative_to_root ?>reports/client_reports.php" class="nav-item <?= $normalized_current == '/reports/client_reports.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span>Client Reports</span>
                    </a>
                    <a href="<?= $relative_to_root ?>reports/vehicle_reports.php" class="nav-item <?= $normalized_current == '/reports/vehicle_reports.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-truck-moving w-5"></i>
                        <span>Vehicle Reports</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Admin Section (Admin and above) -->
            <?php if (hasRoleAccess('admin', $user_role, $role_hierarchy)): ?>
            <div class="mt-6">
                <button class="section-toggle w-full flex items-center justify-between px-4 py-2 text-xs font-semibold text-teal-300 uppercase tracking-wider hover:text-white transition-colors" 
                        data-section="admin">
                    <span>Administration</span>
                    <i class="fas fa-chevron-down transform transition-transform <?= $active_section == 'admin' ? 'rotate-180' : '' ?>"></i>
                </button>
                <div class="section-content <?= $active_section != 'admin' ? 'hidden' : '' ?>" data-section="admin">
                    <a href="<?= $relative_to_root ?>admin/manage_users.php" class="nav-item <?= $normalized_current == '/admin/manage_users.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-users-cog w-5"></i>
                        <span>Manage Users</span>
                    </a>
                    <a href="<?= $relative_to_root ?>admin/system_settings.php" class="nav-item <?= $normalized_current == '/admin/system_settings.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-cog w-5"></i>
                        <span>System Settings</span>
                    </a>
                    <a href="<?= $relative_to_root ?>admin/audit_logs.php" class="nav-item <?= $normalized_current == '/admin/audit_logs.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-history w-5"></i>
                        <span>Audit Logs</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Messages/Service (All users can view) -->
            <div class="mt-6">
                <button class="section-toggle w-full flex items-center justify-between px-4 py-2 text-xs font-semibold text-teal-300 uppercase tracking-wider hover:text-white transition-colors" 
                        data-section="communication">
                    <span>Communication</span>
                    <i class="fas fa-chevron-down transform transition-transform <?= $active_section == 'communication' ? 'rotate-180' : '' ?>"></i>
                </button>
                <div class="section-content <?= $active_section != 'communication' ? 'hidden' : '' ?>" data-section="communication">
                    <a href="<?= $relative_to_root ?>communication/messages.php" class="nav-item <?= $normalized_current == '/communication/messages.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-envelope w-5"></i>
                        <span>Messages</span>
                    </a>
                    <a href="<?= $relative_to_root ?>communication/service_requests.php" class="nav-item <?= $normalized_current == '/communication/service_requests.php' ? 'nav-active' : 'text-teal-100' ?> flex items-center space-x-3 px-4 py-3 rounded-lg font-medium ml-4">
                        <i class="fas fa-tools w-5"></i>
                        <span>Service</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- User Actions Section -->
    <div class="p-4 border-t border-teal-500 space-y-2">
        <a href="<?= $relative_to_root ?>user_profile.php" class="nav-item text-teal-100 hover:text-white flex items-center space-x-3 px-4 py-3 rounded-lg font-medium">
            <i class="fas fa-user-edit w-5"></i>
            <span>Edit Profile</span>
        </a>
        <a href="<?= $relative_to_root ?>logout.php" class="nav-item text-teal-100 hover:text-red-300 flex items-center space-x-3 px-4 py-3 rounded-lg font-medium">
            <i class="fas fa-sign-out-alt w-5"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

<style>
.nav-active {
    background: white;
    color: #0d9488 !important;
}

.nav-item {
    transition: all 0.2s ease;
}

.nav-item:hover:not(.nav-active) {
    background: rgba(255, 255, 255, 0.1);
    color: white;
}

.section-toggle {
    transition: all 0.2s ease;
}

.section-toggle:hover {
    background: rgba(255, 255, 255, 0.1);
}

.section-content {
    transition: all 0.3s ease;
    overflow: hidden;
}

.section-content.hidden {
    max-height: 0;
    opacity: 0;
}

.section-content:not(.hidden) {
    max-height: 500px;
    opacity: 1;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sectionToggles = document.querySelectorAll('.section-toggle');
    
    sectionToggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            const sectionName = this.dataset.section;
            const content = document.querySelector(`.section-content[data-section="${sectionName}"]`);
            const chevron = this.querySelector('.fa-chevron-down');
            
            // Close all other sections
            document.querySelectorAll('.section-content').forEach(section => {
                if (section !== content) {
                    section.classList.add('hidden');
                }
            });
            
            // Reset all other chevrons
            document.querySelectorAll('.section-toggle .fa-chevron-down').forEach(icon => {
                if (icon !== chevron) {
                    icon.classList.remove('rotate-180');
                }
            });
            
            // Toggle current section
            content.classList.toggle('hidden');
            chevron.classList.toggle('rotate-180');
        });
    });
});
</script>