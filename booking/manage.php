<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// Ensure CSRF token exists for AJAX delete
if (empty($_SESSION['csrf'])) {
	$_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Check if user has manager1 access
if ($_SESSION['role'] !== 'manager1' && $_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin') {
	header("Location: ../dashboard.php");
	exit();
}


// Filters
$client_id_f = isset($_GET['client_id']) && $_GET['client_id'] !== '' ? (int)$_GET['client_id'] : null;
$from_id_f = isset($_GET['from_location_id']) && $_GET['from_location_id'] !== '' ? (int)$_GET['from_location_id'] : null;
$to_id_f = isset($_GET['to_location_id']) && $_GET['to_location_id'] !== '' ? (int)$_GET['to_location_id'] : null;
$status_f = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : 'pending';
$date_from_f = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : date('Y-m-01');
$date_to_f = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : date('Y-m-t');

// Build filtered query
$where = ["DATE(b.created_at) BETWEEN ? AND ?"]; // default to current month
$params = [$date_from_f, $date_to_f];

if ($status_f !== 'all') { $where[] = "b.status = ?"; $params[] = $status_f; }
if ($client_id_f) { $where[] = "b.client_id = ?"; $params[] = $client_id_f; }
if ($from_id_f) { $where[] = "b.from_location_id = ?"; $params[] = $from_id_f; }
if ($to_id_f) { $where[] = "b.to_location_id = ?"; $params[] = $to_id_f; }

$sql = "
    SELECT 
        b.*,
        c.client_name,
        c.client_code,
        fl.location as from_location,
        tl.location as to_location,
        u.full_name as created_by_name
    FROM bookings b
    LEFT JOIN clients c ON b.client_id = c.id
    LEFT JOIN location fl ON b.from_location_id = fl.id
    LEFT JOIN location tl ON b.to_location_id = tl.id
    LEFT JOIN users u ON b.created_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Data sources for filters / autocomplete
$clients_list = $pdo->query("SELECT id, client_name, client_code FROM clients WHERE status='active' ORDER BY client_name")->fetchAll();
$locations_list = $pdo->query("SELECT id, location FROM location ORDER BY location")->fetchAll();

// Prefill client search text
$client_prefill = '';
if ($client_id_f) {
    foreach ($clients_list as $cl) {
        if ((int)$cl['id'] === $client_id_f) {
            $client_prefill = $cl['client_name'] . ' (' . $cl['client_code'] . ')';
            break;
        }
    }
}

// Get container details for each booking
$booking_containers = [];
if (!empty($bookings)) {
    try {
        // Check if booking_containers table exists
        $existsStmt = $pdo->query("SHOW TABLES LIKE 'booking_containers'");
        $exists = $existsStmt->fetch();
        if ($exists) {
            $booking_ids = array_column($bookings, 'id');
            $placeholders = str_repeat('?,', count($booking_ids) - 1) . '?';
            
            $container_stmt = $pdo->prepare("
                SELECT 
                    booking_id,
                    container_sequence,
                    container_type,
                    container_number_1,
                    container_number_2
                FROM booking_containers 
                WHERE booking_id IN ($placeholders)
                ORDER BY booking_id, container_sequence
            ");
            $container_stmt->execute($booking_ids);
            $containers = $container_stmt->fetchAll();
            
            // Group containers by booking_id
            foreach ($containers as $container) {
                $booking_containers[$container['booking_id']][] = $container;
            }
        }
    } catch (Exception $e) {
        // If table missing or any error, continue without container details
        $booking_containers = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings - Absuma Logistics</title>
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
        
        .status-pending { @apply bg-yellow-100 text-yellow-800; }
        .status-confirmed { @apply bg-blue-100 text-blue-800; }
        .status-in_progress { @apply bg-purple-100 text-purple-800; }
        .status-completed { @apply bg-green-100 text-green-800; }
        .status-cancelled { @apply bg-red-100 text-red-800; }
        /* Inputs matching page style */
        .input-enhanced { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
        .input-enhanced:focus { outline: none; border-color: var(--absuma-red); box-shadow: 0 0 0 3px rgba(220,38,37,0.1); }
        select.input-enhanced { -webkit-appearance: none; -moz-appearance: none; appearance: none; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e"); background-position: right 12px center; background-repeat: no-repeat; background-size: 16px; padding-right: 40px; }
        .filters-bar { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 12px; }
        .filters-col { grid-column: span 12 / span 12; }
        @media (min-width: 768px) {
            .filters-col-3 { grid-column: span 3 / span 3; }
            .filters-col-2 { grid-column: span 2 / span 2; }
            .filters-col-4 { grid-column: span 4 / span 4; }
        }
        /* Autocomplete dropdown */
        .autocomplete-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; max-height: 240px; overflow-y: auto; z-index: 30; display: none; box-shadow: 0 10px 25px -5px rgba(0,0,0,.1), 0 10px 10px -5px rgba(0,0,0,.04); }
        .autocomplete-item { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; cursor: pointer; }
        .autocomplete-item:hover { background: #fef2f2; }
    </style>
</head>
<body class="gradient-bg overflow-x-hidden">
    <div class="min-h-screen flex">
        <!-- Sidebar (same as create.php) -->
        <?php include '../sidebar_navigation.php'; ?>

        <!-- Right content shell matching create.php -->
        <div class="flex-1 flex flex-col">
            <main class="flex-1 p-6 overflow-auto">
                <div class="max-w-7xl mx-auto">
                    <div class="space-y-6">
                        <!-- Page Header -->
                        <div class="bg-white rounded-xl shadow-soft p-6 border-l-4 border-absuma-red">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-800 mb-2">
                                        <i class="fas fa-clipboard-list text-absuma-red mr-3"></i>
                                        Manage Bookings
                                    </h2>
                                    <p class="text-gray-600">View and manage all container bookings</p>
                                </div>
                                <div class="flex gap-3">
                                    <a href="create.php" class="bg-absuma-red hover:bg-absuma-red-dark text-white px-3 py-1.5 rounded-lg transition-all duration-200 shadow-md hover:shadow-lg flex items-center text-sm">
                                        <i class="fas fa-plus mr-2"></i>
                                        New Booking
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Filters -->
                        <form id="filtersForm" method="get" class="bg-white rounded-xl shadow-soft p-4 border border-gray-100">
                            <div class="filters-bar">
                                <!-- Client Autocomplete -->
                                <div class="filters-col filters-col-3">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Client</label>
                                    <div class="relative">
                                        <input type="text" id="client_search" class="input-enhanced pr-10" placeholder="Type to search clients..." autocomplete="off" value="<?= htmlspecialchars($client_prefill) ?>">
                                        <input type="hidden" name="client_id" id="client_id" value="<?= htmlspecialchars($client_id_f ?? '') ?>">
                                        <div id="client_dropdown" class="autocomplete-dropdown"></div>
                                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                                    </div>
                                </div>
                                <!-- From Location (autocomplete) -->
                                <div class="filters-col filters-col-2">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">From Location</label>
                                    <div class="relative">
                                        <input type="text" id="from_location_search" class="input-enhanced pr-10 text-sm" placeholder="Type to search..." autocomplete="off" value="<?php 
                                            if ($from_id_f) { foreach ($locations_list as $l) { if ((int)$l['id']===$from_id_f) { echo htmlspecialchars($l['location']); break; } } } 
                                        ?>">
                                        <input type="hidden" name="from_location_id" id="from_location_id" value="<?= htmlspecialchars($from_id_f ?? '') ?>">
                                        <div id="from_location_dropdown" class="autocomplete-dropdown"></div>
                                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                                    </div>
                                </div>
                                <!-- To Location (autocomplete) -->
                                <div class="filters-col filters-col-2">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">To Location</label>
                                    <div class="relative">
                                        <input type="text" id="to_location_search" class="input-enhanced pr-10 text-sm" placeholder="Type to search..." autocomplete="off" value="<?php 
                                            if ($to_id_f) { foreach ($locations_list as $l) { if ((int)$l['id']===$to_id_f) { echo htmlspecialchars($l['location']); break; } } } 
                                        ?>">
                                        <input type="hidden" name="to_location_id" id="to_location_id" value="<?= htmlspecialchars($to_id_f ?? '') ?>">
                                        <div id="to_location_dropdown" class="autocomplete-dropdown"></div>
                                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                                    </div>
                                </div>
                                <!-- Status -->
                                <div class="filters-col filters-col-2">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                                    <select name="status" class="input-enhanced text-sm">
                                        <?php $statuses = ['pending'=>'Pending','confirmed'=>'Confirmed','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled','all'=>'All'];
                                        foreach ($statuses as $k=>$v): ?>
                                            <option value="<?= $k ?>" <?= $status_f===$k?'selected':'' ?>><?= $v ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <!-- Date Range (single calendar) -->
                                <div class="filters-col filters-col-3">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Date Range</label>
                                    <div class="relative">
                                        <input type="text" id="date_range_display" class="input-enhanced text-sm pr-10" readonly value="<?= date('d/m/Y', strtotime($date_from_f)) ?> - <?= date('d/m/Y', strtotime($date_to_f)) ?>">
                                        <input type="hidden" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from_f) ?>">
                                        <input type="hidden" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to_f) ?>">
                                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none"><i class="fas fa-calendar text-gray-400"></i></div>
                                        <div id="daterange_panel" class="hidden absolute mt-2 bg-white border border-gray-200 rounded-lg shadow-xl z-40 p-3 w-[280px]"></div>
                                    </div>
                                </div>
                                <!-- Reset -->
                                <div class="filters-col filters-col-2 flex items-end">
                                    <button type="button" id="resetFilters" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-800 px-3 py-2 rounded-lg text-sm">Reset</button>
                                </div>
                            </div>
                        </form>

                        <!-- Bookings Table -->
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden">
                            <div class="overflow-x-auto w-full">
                                <table class="w-full table-auto divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Booking ID</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Containers</th>
                                            
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Route</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (empty($bookings)): ?>
                                            <tr>
                                                <td colspan="8" class="px-3 py-8 text-center text-gray-500">
                                                    <i class="fas fa-inbox text-3xl text-gray-300 mb-2"></i>
                                                    <p class="text-sm">No bookings found</p>
                                                    <p class="text-xs text-gray-400">Create your first booking to get started</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($bookings as $booking): ?>
                                                <tr class="hover:bg-gray-50 transition-colors">
                                                    <td class="px-3 py-3 max-w-[120px]">
                                                        <div class="text-xs font-medium text-gray-900 truncate" title="<?= htmlspecialchars($booking['booking_id']) ?>">
                                                            <?= htmlspecialchars($booking['booking_id']) ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-3 py-3 max-w-[140px]">
                                                        <div class="text-xs text-gray-900 truncate" title="<?= htmlspecialchars($booking['client_name']) ?>">
                                                            <?= htmlspecialchars($booking['client_name']) ?>
                                                        </div>
                                                        <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars($booking['client_code']) ?></div>
                                                    </td>
                                                    <td class="px-3 py-3 text-center">
                                                        <div class="text-xs text-gray-900 font-medium"><?= $booking['no_of_containers'] ?></div>
                                                    </td>
                                                    <td class="px-3 py-3 max-w-[160px]">
                                                        <?php if ($booking['from_location'] || $booking['to_location']): ?>
                                                            <div class="text-xs">
                                                                <div class="text-gray-900 truncate" title="<?= $booking['from_location'] ? htmlspecialchars($booking['from_location']) : 'N/A' ?>">
                                                                    <?= $booking['from_location'] ? htmlspecialchars($booking['from_location']) : 'N/A' ?>
                                                                </div>
                                                                <div class="text-center text-gray-400 text-xs">↓</div>
                                                                <div class="text-gray-900 truncate" title="<?= $booking['to_location'] ? htmlspecialchars($booking['to_location']) : 'N/A' ?>">
                                                                    <?= $booking['to_location'] ? htmlspecialchars($booking['to_location']) : 'N/A' ?>
                                                                </div>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="text-xs text-gray-500">Not specified</div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-3 py-3 text-center">
                                                        <?php 
                                                            $status = $booking['status'];
                                                            $badge = 'bg-gray-100 text-gray-800';
                                                            if ($status === 'pending') $badge = 'bg-yellow-100 text-yellow-800';
                                                            elseif ($status === 'confirmed') $badge = 'bg-blue-100 text-blue-800';
                                                            elseif ($status === 'in_progress') $badge = 'bg-purple-100 text-purple-800';
                                                            elseif ($status === 'completed') $badge = 'bg-green-100 text-green-800';
                                                            elseif ($status === 'cancelled') $badge = 'bg-red-100 text-red-800';
                                                        ?>
                                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $badge ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $status)) ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-3 py-3 max-w-[120px]">
                                                        <div class="text-xs text-gray-900"><?= date('M j, Y', strtotime($booking['created_at'])) ?></div>
                                                        <div class="text-xs text-gray-500 truncate" title="by <?= htmlspecialchars($booking['created_by_name']) ?>">
                                                            by <?= htmlspecialchars($booking['created_by_name']) ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-3 py-3 text-center">
                                                        <div class="flex gap-2 justify-center">
                                                            <a href="view.php?id=<?= (int)$booking['id'] ?>" class="text-absuma-red hover:text-absuma-red-dark" title="View Details">
                                                                <i class="fas fa-eye text-sm"></i>
                                                            </a>
                                                            <a href="edit.php?booking_id=<?= urlencode($booking['booking_id']) ?>" class="text-blue-600 hover:text-blue-800" title="Edit">
                                                                <i class="fas fa-edit text-sm"></i>
                                                            </a>
                                                            <a href="#" data-booking-id="<?= (int)$booking['id'] ?>" data-booking-code="<?= htmlspecialchars($booking['booking_id']) ?>" class="text-red-600 hover:text-red-800 js-delete-booking" title="Delete">
                                                                <i class="fas fa-trash text-sm"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    </div>
                </main>
        </div>
    </div>
</body>
</html>
<script>
// Client autocomplete (matches create page behavior)
const allClients = <?= json_encode($clients_list) ?>;
const allLocations = <?= json_encode($locations_list) ?>;
const clientInput = document.getElementById('client_search');
const clientDropdown = document.getElementById('client_dropdown');
const clientHidden = document.getElementById('client_id');

function renderClients(list) {
    if (!clientDropdown) return;
    if (!list || list.length === 0) {
        clientDropdown.innerHTML = '<div class="p-3 text-gray-500 text-sm">No clients found</div>';
    } else {
        clientDropdown.innerHTML = list.map(c => (
            '<div class="autocomplete-item" data-id="' + c.id + '" data-text="' + c.client_name + ' (' + c.client_code + ')">' +
                '<div class="font-medium text-gray-900">' + c.client_name + '</div>' +
                '<div class="text-xs text-gray-500">' + c.client_code + '</div>' +
            '</div>'
        )).join('');
    }
    clientDropdown.style.display = 'block';
}

if (clientInput && clientDropdown && clientHidden) {
    clientInput.addEventListener('focus', () => renderClients(allClients));
    clientInput.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        if (!q) {
            clientHidden.value = '';
            renderClients(allClients);
            return;
        }
        const filtered = allClients.filter(c => c.client_name.toLowerCase().includes(q) || c.client_code.toLowerCase().includes(q));
        renderClients(filtered);
    });
    clientDropdown.addEventListener('click', function(e) {
        const item = e.target.closest('.autocomplete-item');
        if (!item) return;
        clientInput.value = item.getAttribute('data-text');
        clientHidden.value = item.getAttribute('data-id');
        clientDropdown.style.display = 'none';
        document.getElementById('filtersForm').requestSubmit();
    });
    document.addEventListener('click', function(e){
        if (!clientInput.contains(e.target) && !clientDropdown.contains(e.target)) clientDropdown.style.display = 'none';
    });
}

// Location autocompletes
function setupLocationAutocomplete(inputId, hiddenId, dropdownId) {
    const input = document.getElementById(inputId);
    const hidden = document.getElementById(hiddenId);
    const dropdown = document.getElementById(dropdownId);
    if (!input || !hidden || !dropdown) return;
    const render = (list) => {
        dropdown.innerHTML = list.length ? list.map(l => (
            '<div class="autocomplete-item" data-id="'+l.id+'" data-name="'+l.location+'">'+l.location+'</div>'
        )).join('') : '<div class="p-3 text-gray-500 text-sm">No locations found</div>';
        dropdown.style.display = 'block';
    };
    input.addEventListener('focus', ()=>render(allLocations));
    input.addEventListener('input', function(){
        const q = this.value.toLowerCase().trim();
        if (!q) { hidden.value=''; render(allLocations); return; }
        render(allLocations.filter(l => l.location.toLowerCase().includes(q)));
    });
    dropdown.addEventListener('click', function(e){
        const item = e.target.closest('.autocomplete-item'); if (!item) return;
        input.value = item.getAttribute('data-name');
        hidden.value = item.getAttribute('data-id');
        dropdown.style.display = 'none';
        document.getElementById('filtersForm').requestSubmit();
    });
    document.addEventListener('click', (e)=>{ if (!input.contains(e.target) && !dropdown.contains(e.target)) dropdown.style.display='none'; });
}

setupLocationAutocomplete('from_location_search','from_location_id','from_location_dropdown');
setupLocationAutocomplete('to_location_search','to_location_id','to_location_dropdown');

// Date range mini picker (single calendar)
const datePanel = document.getElementById('daterange_panel');
const dateDisplay = document.getElementById('date_range_display');
const dateFrom = document.getElementById('date_from');
const dateTo = document.getElementById('date_to');
let pickingStart = true;
let currentCalendarDate = new Date(); // Track current calendar view

function formatYmd(d){ return d.toISOString().slice(0,10); }
function fmtDisplay(d){ return d.toLocaleDateString('en-GB'); }

function buildCalendar(showDate){
    if (!datePanel) return;
    
    // Update the current calendar date
    currentCalendarDate = new Date(showDate);
    
    const year = currentCalendarDate.getFullYear();
    const month = currentCalendarDate.getMonth();
    const first = new Date(year, month, 1);
    const last = new Date(year, month + 1, 0);
    const startWeekday = (first.getDay() === 0 ? 7 : first.getDay());
    
    let html = '<div class="flex items-center justify-between mb-2">'
        + '<button type="button" id="dr_prev" class="px-2 py-1 text-sm hover:bg-gray-100 rounded">‹</button>'
        + '<div class="font-semibold text-sm">' + first.toLocaleString('en-GB', { month: 'long', year: 'numeric' }) + '</div>'
        + '<button type="button" id="dr_next" class="px-2 py-1 text-sm hover:bg-gray-100 rounded">›</button>'
        + '</div>';
    
    html += '<div class="grid grid-cols-7 gap-1 text-xs text-gray-500 mb-1">'
        + '<div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div></div>';
    
    html += '<div class="grid grid-cols-7 gap-1 text-sm">';
    
    // Empty cells for days before month starts
    for (let i=1;i<startWeekday;i++) html += '<div></div>';
    
    // Days of the month
    for (let d=1; d<=last.getDate(); d++) {
        const curr = new Date(year, month, d);
        const ymd = formatYmd(curr);
        const inRange = (dateFrom.value && dateTo.value && ymd >= dateFrom.value && ymd <= dateTo.value);
        const isStart = dateFrom.value === ymd;
        const isEnd = dateTo.value === ymd;
        let cls = 'px-2 py-1 text-center rounded cursor-pointer hover:bg-red-50';
        if (isStart || isEnd) cls += ' bg-absuma-red text-white';
        else if (inRange) cls += ' bg-red-100';
        html += '<div class="'+cls+'" data-date="'+ymd+'">'+d+'</div>';
    }
    html += '</div>';
    
    datePanel.innerHTML = html;
    
    // Month navigation - preserve current calendar view and prevent panel from closing
    const prevBtn = document.getElementById('dr_prev');
    const nextBtn = document.getElementById('dr_next');
    if (prevBtn) {
        prevBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const prevMonth = new Date(currentCalendarDate.getFullYear(), currentCalendarDate.getMonth() - 1, 1);
            buildCalendar(prevMonth);
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const nextMonth = new Date(currentCalendarDate.getFullYear(), currentCalendarDate.getMonth() + 1, 1);
            buildCalendar(nextMonth);
        });
    }
    
    // Date selection
    datePanel.querySelectorAll('[data-date]').forEach(el => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ymd = el.getAttribute('data-date');
            if (pickingStart) {
                dateFrom.value = ymd;
                dateTo.value = ymd;
                pickingStart = false;
            } else {
                if (ymd < dateFrom.value) { 
                    dateTo.value = dateFrom.value; 
                    dateFrom.value = ymd; 
                } else { 
                    dateTo.value = ymd; 
                }
                pickingStart = true;
                const df = new Date(dateFrom.value + 'T00:00:00');
                const dt = new Date(dateTo.value + 'T00:00:00');
                dateDisplay.value = fmtDisplay(df) + ' - ' + fmtDisplay(dt);
                document.getElementById('filtersForm').requestSubmit();
            }
            // Keep showing the same month after selection
            buildCalendar(currentCalendarDate);
        });
    });
}
if (dateDisplay && datePanel) {
    dateDisplay.addEventListener('click', (e) => {
        e.stopPropagation();
        datePanel.classList.toggle('hidden');
        const base = dateFrom.value ? new Date(dateFrom.value + 'T00:00:00') : new Date();
        buildCalendar(base);
    });
    // Prevent clicks inside the panel from bubbling to the document
    datePanel.addEventListener('click', (e) => { e.stopPropagation(); });
    document.addEventListener('click', (e)=>{
        if (!datePanel.contains(e.target) && !dateDisplay.contains(e.target)) datePanel.classList.add('hidden');
    });
}

// Auto-apply on change
const form = document.getElementById('filtersForm');
if (form) {
    // only selects auto-submit; date range handled by custom picker
    form.querySelectorAll('select').forEach(el => {
        el.addEventListener('change', () => form.requestSubmit());
    });
    document.getElementById('resetFilters')?.addEventListener('click', () => {
        const params = new URLSearchParams({
            status: 'pending',
            date_from: '<?= date('Y-m-01') ?>',
            date_to: '<?= date('Y-m-t') ?>'
        });
        window.location = 'manage.php?' + params.toString();
    });
}
</script>

<!-- Delete Confirmation Modal (matches Location styling) -->
<div id="bookingDeleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
  <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
    <div class="mt-3 text-center">
      <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
      </div>
      <h3 class="text-lg font-medium text-gray-900 mb-2">Delete Booking</h3>
      <div class="mt-2 px-7 py-3">
        <p class="text-sm text-gray-500">
          Are you sure you want to delete booking <span id="bookingDelCode" class="font-semibold text-gray-900"></span>?
        </p>
        <p class="text-sm text-red-600 mt-2">This action cannot be undone.</p>
      </div>
      <div class="flex gap-3 justify-center mt-4">
        <button id="bookingConfirmDelete" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
          <i class="fas fa-trash mr-1"></i>Delete
        </button>
        <button id="bookingCancelDelete" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg text-sm font-medium transition-colors">
          <i class="fas fa-times mr-1"></i>Cancel
        </button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  let currentBookingId = null;
  const modal = document.getElementById('bookingDeleteModal');
  const codeSpan = document.getElementById('bookingDelCode');
  const confirmBtn = document.getElementById('bookingConfirmDelete');
  const cancelBtn = document.getElementById('bookingCancelDelete');

  function openModal(id, code){
    currentBookingId = id;
    codeSpan.textContent = code;
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }
  function closeModal(){
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
    currentBookingId = null;
  }

  // Delegate clicks on delete icons
  document.body.addEventListener('click', function(e){
    const trigger = e.target.closest('a.js-delete-booking');
    if (!trigger) return;
    e.preventDefault();
    const id = trigger.getAttribute('data-booking-id');
    const code = trigger.getAttribute('data-booking-code');
    openModal(id, code);
  });

  // Confirm: POST form to delete.php (uses existing CSRF token)
  confirmBtn.addEventListener('click', function(){
    if (!currentBookingId) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'delete.php';
    const inId = document.createElement('input'); inId.type='hidden'; inId.name='id'; inId.value=currentBookingId; form.appendChild(inId);
    const inCsrf = document.createElement('input'); inCsrf.type='hidden'; inCsrf.name='csrf'; inCsrf.value='<?= htmlspecialchars($_SESSION['csrf']) ?>'; form.appendChild(inCsrf);
    document.body.appendChild(form);
    form.submit();
  });

  // Cancel and close behaviors
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
})();
</script>
