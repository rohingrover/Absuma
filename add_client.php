<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Collect general info - UPDATED with billing_address
        $client_data = [
            'client_name' => trim($_POST['client_name']),
            'contact_person' => trim($_POST['contact_person']),
            'phone_number' => trim($_POST['phone_number']),
            'email_address' => trim($_POST['email_address']),
            'billing_address' => trim($_POST['billing_address']), // NEW FIELD
            'billing_cycle_days' => (int)$_POST['billing_cycle_days'],
            'pan_number' => strtoupper(trim($_POST['pan_number'])),
            'gst_number' => strtoupper(trim($_POST['gst_number'])),
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Validation - UPDATED with billing_address validation
        $errors = [];
        
        if (empty($client_data['client_name'])) {
            $errors[] = "Client name is required";
        }
        
        if (empty($client_data['contact_person'])) {
            $errors[] = "Contact person name is required";
        }
        
        if (empty($client_data['phone_number']) || !preg_match('/^[0-9]{10}$/', $client_data['phone_number'])) {
            $errors[] = "Valid 10-digit phone number is required";
        }
        
        if (empty($client_data['email_address']) || !filter_var($client_data['email_address'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email address is required";
        }
        
        // NEW: Billing address validation
        if (empty($client_data['billing_address'])) {
            $errors[] = "Billing address is required";
        } elseif (strlen($client_data['billing_address']) < 10) {
            $errors[] = "Billing address must be at least 10 characters long";
        }
        
        if ($client_data['billing_cycle_days'] < 1 || $client_data['billing_cycle_days'] > 365) {
            $errors[] = "Billing cycle must be between 1 and 365 days";
        }
        
        if (!empty($client_data['pan_number']) && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $client_data['pan_number'])) {
            $errors[] = "Invalid PAN number format (e.g., ABCDE1234F)";
        }
        
        if (!empty($client_data['gst_number']) && strlen($client_data['gst_number']) !== 15) {
            $errors[] = "GST number must be 15 characters long";
        }
        
        // Check for duplicate email
        $emailCheck = $pdo->prepare("SELECT id FROM clients WHERE email_address = ? AND deleted_at IS NULL");
        $emailCheck->execute([$client_data['email_address']]);
        if ($emailCheck->rowCount() > 0) {
            $errors[] = "Email address already exists";
        }
        
        // Check for duplicate PAN if provided
        if (!empty($client_data['pan_number'])) {
            $panCheck = $pdo->prepare("SELECT id FROM clients WHERE pan_number = ? AND deleted_at IS NULL");
            $panCheck->execute([$client_data['pan_number']]);
            if ($panCheck->rowCount() > 0) {
                $errors[] = "PAN number already exists";
            }
        }
        
        // Check for duplicate GST if provided
        if (!empty($client_data['gst_number'])) {
            $gstCheck = $pdo->prepare("SELECT id FROM clients WHERE gst_number = ? AND deleted_at IS NULL");
            $gstCheck->execute([$client_data['gst_number']]);
            if ($gstCheck->rowCount() > 0) {
                $errors[] = "GST number already exists";
            }
        }
        
        if (!empty($errors)) {
            throw new Exception(implode("<br>", $errors));
        }
        
        // UPDATED: Insert client data with billing_address
        $sql = "INSERT INTO clients (
            client_name, contact_person, phone_number, email_address, billing_address,
            billing_cycle_days, pan_number, gst_number, status, created_by, created_at
        ) VALUES (
            :client_name, :contact_person, :phone_number, :email_address, :billing_address,
            :billing_cycle_days, :pan_number, :gst_number, 'active', :created_by, :created_at
        )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($client_data);
        $client_id = $pdo->lastInsertId();
        
        // Generate client code
        $client_code = 'CLT' . str_pad($client_id, 6, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE clients SET client_code = ? WHERE id = ?")
             ->execute([$client_code, $client_id]);
        
        // Rest of your rate processing code...
        
        $pdo->commit();
        $success = "Client registered successfully! Client Code: " . $client_code;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Initialize form data with billing_address
$formData = [
    'client_name' => $_POST['client_name'] ?? '',
    'contact_person' => $_POST['contact_person'] ?? '',
    'phone_number' => $_POST['phone_number'] ?? '',
    'email_address' => $_POST['email_address'] ?? '',
    'billing_address' => $_POST['billing_address'] ?? '', // NEW FIELD
    'billing_cycle_days' => $_POST['billing_cycle_days'] ?? '30',
    'pan_number' => $_POST['pan_number'] ?? '',
    'gst_number' => $_POST['gst_number'] ?? ''
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Client - Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="notifications.js"></script>
    <style>
        .form-grid {
            @apply grid grid-cols-1 md:grid-cols-2 gap-4;
        }
        .form-field {
            @apply space-y-1;
        }
        .card-hover-effect {
            @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
        }
        .rate-row {
            @apply grid grid-cols-12 gap-2 items-end mb-3 p-3 bg-gray-50 rounded-lg;
        }
        .rate-row:hover {
            @apply bg-gray-100;
        }
        .remove-rate {
            @apply text-red-600 hover:text-red-800 cursor-pointer p-1 rounded hover:bg-red-100;
        }
		.bg-blue-25 {
		background-color: rgba(59, 130, 246, 0.05);
		}

		.bg-green-25 {
			background-color: rgba(34, 197, 94, 0.05);
		}

		.location-field {
			transition: opacity 0.3s ease;
		}

		.rate-row {
			transition: all 0.3s ease;
		}

		.rate-row:hover {
			box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
		}
			.autocomplete-dropdown {
		max-height: 200px;
		overflow-y: auto;
		border: 1px solid #d1d5db;
		border-radius: 0.375rem;
		box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
		z-index: 1000;
	}

	.autocomplete-item {
		transition: background-color 0.15s ease;
	}

	.autocomplete-item:hover {
		background-color: #eff6ff;
	}

	.autocomplete-item.bg-blue-100 {
		background-color: #dbeafe;
	}

	.location-field.opacity-50 {
		transition: opacity 0.3s ease;
	}

	.autocomplete-input:focus {
		box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
	}
	.form-field.col-span-2 {
    grid-column: span 2;
}

	textarea:focus {
		box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.1);
	}

	.billing-address-valid {
		border-color: #10b981;
		background-color: #f0fdf4;
	}

	.billing-address-invalid {
		border-color: #ef4444;
		background-color: #fef2f2;
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
                            <i class="fas fa-user-tie text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Fleet Management System</h1>
                            <p class="text-blue-600 text-sm">Add New Client</p>
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
                                <a href="add_client.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg">
                                    <i class="fas fa-plus w-5 text-center"></i> Add New Client
                                </a>
                                <a href="manage_clients.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-list w-5 text-center"></i> Manage Clients
                                </a>
                                <a href="client_reports.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-chart-line w-5 text-center"></i> Client Reports
                                </a>
                                <a href="dashboard.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-tachometer-alt w-5 text-center"></i> Dashboard
                                </a>
                            </nav>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1">
                    <div id="alertContainer">
                        <?php if (!empty($success)): ?>
                            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-check-circle text-green-600"></i>
                                    <p><?= htmlspecialchars($success) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($error)): ?>
                            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-exclamation-circle text-red-600"></i>
                                    <div><?= $error ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="space-y-6">
                        <!-- General Information -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-info-circle text-blue-600"></i> General Information
                                </h2>
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label for="client_name" class="block text-sm font-medium text-gray-700">Client Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="client_name" id="client_name" required 
                                               value="<?= htmlspecialchars($formData['client_name']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter client/company name">
                                    </div>
                                    
                                    <div class="form-field">
                                        <label for="contact_person" class="block text-sm font-medium text-gray-700">Contact Person Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="contact_person" id="contact_person" required 
                                               value="<?= htmlspecialchars($formData['contact_person']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter contact person name">
                                    </div>
                                    
                                    <div class="form-field">
                                        <label for="phone_number" class="block text-sm font-medium text-gray-700">Phone Number <span class="text-red-500">*</span></label>
                                        <input type="tel" name="phone_number" id="phone_number" required 
                                               value="<?= htmlspecialchars($formData['phone_number']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter 10-digit phone number" pattern="[0-9]{10}">
                                    </div>
                                    
                                    <div class="form-field">
                                        <label for="email_address" class="block text-sm font-medium text-gray-700">Email Address <span class="text-red-500">*</span></label>
                                        <input type="email" name="email_address" id="email_address" required 
                                               value="<?= htmlspecialchars($formData['email_address']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter email address">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Billing Information -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-file-invoice text-blue-600"></i> Billing Information
                                </h2>
								<div class="form-grid">
            <!-- Billing Address -->
            <div class="form-field col-span-2">
                <label for="billing_address" class="block text-sm font-medium text-gray-700">
                    Billing Address <span class="text-red-500">*</span>
                </label>
                <textarea name="billing_address" id="billing_address" required rows="3"
                          class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-purple-500 focus:border-purple-500 resize-vertical"
                          placeholder="Enter complete billing address&#10;e.g., 123 Business Park, Sector 15,&#10;Mumbai, Maharashtra - 400001"></textarea>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                    This address will be used for invoicing and official correspondence
                </p>
            </div>
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label for="billing_cycle_days" class="block text-sm font-medium text-gray-700">Billing Cycle (Days) <span class="text-red-500">*</span></label>
                                        <input type="number" name="billing_cycle_days" id="billing_cycle_days" required min="1" max="365"
                                               value="<?= htmlspecialchars($formData['billing_cycle_days']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="e.g. 30">
                                        <p class="text-xs text-gray-500 mt-1">Number of days between billing cycles (1-365)</p>
                                    </div>
                                    
                                    <div class="form-field">
                                        <label for="pan_number" class="block text-sm font-medium text-gray-700">PAN Number</label>
                                        <input type="text" name="pan_number" id="pan_number" 
                                               value="<?= htmlspecialchars($formData['pan_number']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="e.g. ABCDE1234F" pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}">
                                    </div>
                                    
                                    <div class="form-field md:col-span-2">
                                        <label for="gst_number" class="block text-sm font-medium text-gray-700">GST Number</label>
                                        <input type="text" name="gst_number" id="gst_number" 
                                               value="<?= htmlspecialchars($formData['gst_number']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter 15-character GST number" maxlength="15">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contractual Rates for 20ft Containers -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
    <div class="p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
            <i class="fas fa-money-bill-wave text-green-600"></i> Rate Configuration
            <span class="text-sm font-normal text-gray-500 ml-2">(Optional - Add rates only for container sizes you work with)</span>
        </h2>

        <!-- Container Size Selection -->
        <div class="mb-6 p-4 bg-gray-50 rounded-lg">
            <h3 class="text-md font-medium text-gray-700 mb-3">Select Container Sizes</h3>
            <div class="flex gap-4">
                <label class="flex items-center">
                    <input type="checkbox" id="enable20ft" class="mr-2 text-blue-600 focus:ring-blue-500" onchange="toggleContainerSection('20ft')">
                    <span class="text-sm font-medium">20ft Containers</span>
                </label>
                <label class="flex items-center">
                    <input type="checkbox" id="enable40ft" class="mr-2 text-blue-600 focus:ring-blue-500" onchange="toggleContainerSection('40ft')">
                    <span class="text-sm font-medium">40ft Containers</span>
                </label>
            </div>
            <p class="text-xs text-gray-600 mt-2">Select only the container sizes this client works with</p>
        </div>

        <!-- 20ft Container Rates Section -->
        <div id="rates20ftSection" class="rate-section mb-6" style="display: none;">
            <div class="flex justify-between items-center mb-4 p-3 bg-blue-50 rounded-lg">
                <h3 class="text-md font-semibold text-blue-800">20ft Container Rates</h3>
                <button type="button" id="add20ftRate" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700 focus:outline-none">
                    <i class="fas fa-plus mr-1"></i> Add Rate
                </button>
            </div>
            <div id="rates20ftContainer" class="space-y-4">
                <!-- Rates will be added dynamically -->
            </div>
        </div>

        <!-- 40ft Container Rates Section -->
        <div id="rates40ftSection" class="rate-section mb-6" style="display: none;">
            <div class="flex justify-between items-center mb-4 p-3 bg-green-50 rounded-lg">
                <h3 class="text-md font-semibold text-green-800">40ft Container Rates</h3>
                <button type="button" id="add40ftRate" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-green-600 hover:bg-green-700 focus:outline-none">
                    <i class="fas fa-plus mr-1"></i> Add Rate
                </button>
            </div>
            <div id="rates40ftContainer" class="space-y-4">
                <!-- Rates will be added dynamically -->
            </div>
        </div>

        <div class="text-sm text-gray-600 bg-yellow-50 p-3 rounded-lg">
            <i class="fas fa-info-circle text-yellow-600 mr-1"></i>
            <strong>Note:</strong> For Long Distance movements, From and To locations are mandatory. 
            Local movements within the same city may not require specific locations.
        </div>
    </div>
</div>

                        <!-- Form Actions -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="dashboard.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-times mr-2"></i> Cancel
                                    </a>
                                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-save mr-2"></i> Add Client
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </main>
            </div>
        </div>
    </div>


<script>

class LocationAutocomplete {
    constructor() {
        this.debounceTimer = null;
        this.activeDropdown = null;
        this.selectedIndex = -1;
        this.initializeEventListeners();
    }

    initializeEventListeners() {
        // Click outside to close dropdowns
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.relative')) {
                this.hideAllDropdowns();
            }
        });

        // Handle keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (this.activeDropdown) {
                this.handleKeyboardNavigation(e);
            }
        });
    }

    setupAutocomplete(input) {
        const dropdown = input.parentElement.querySelector('.autocomplete-dropdown');
        const hiddenInput = input.parentElement.querySelector('.location-id-input');

        input.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            
            if (query.length < 2) {
                this.hideDropdown(dropdown);
                hiddenInput.value = '';
                return;
            }

            // Debounce the search
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                this.searchLocations(query, dropdown, input, hiddenInput);
            }, 300);
        });

        input.addEventListener('focus', (e) => {
            if (e.target.value.length >= 2) {
                const query = e.target.value.trim();
                this.searchLocations(query, dropdown, input, hiddenInput);
            }
        });

        input.addEventListener('blur', (e) => {
            // Delay hiding to allow for dropdown clicks
            setTimeout(() => {
                if (!dropdown.contains(document.activeElement)) {
                    this.hideDropdown(dropdown);
                }
            }, 150);
        });
    }

    searchLocations(query, dropdown, input, hiddenInput) {
        // Show loading state
        dropdown.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Searching...</div>';
        dropdown.style.display = 'block';
        this.activeDropdown = dropdown;

        fetch(`location_search.php?search=${encodeURIComponent(query)}&limit=10`)
            .then(response => response.json())
            .then(locations => {
                this.displayResults(locations, dropdown, input, hiddenInput);
            })
            .catch(error => {
                console.error('Location search error:', error);
                dropdown.innerHTML = '<div class="px-3 py-2 text-sm text-red-500">Error searching locations</div>';
            });
    }

    displayResults(locations, dropdown, input, hiddenInput) {
        if (locations.length === 0) {
            dropdown.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">No locations found</div>';
            return;
        }

        const html = locations.map((location, index) => 
            `<div class="autocomplete-item px-3 py-2 text-sm cursor-pointer hover:bg-blue-50 border-b border-gray-100 last:border-b-0" 
                  data-location-id="${location.id}" 
                  data-location-name="${location.location}"
                  data-index="${index}">
                <i class="fas fa-map-marker-alt text-blue-500 mr-2"></i>
                ${this.highlightMatch(location.location, input.value)}
            </div>`
        ).join('');

        dropdown.innerHTML = html;

        // Add click listeners to dropdown items
        dropdown.querySelectorAll('.autocomplete-item').forEach(item => {
            item.addEventListener('click', (e) => {
                this.selectLocation(e.target.closest('.autocomplete-item'), input, hiddenInput, dropdown);
            });
        });

        this.selectedIndex = -1;
    }

    highlightMatch(text, query) {
        const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        return text.replace(regex, '<strong class="text-blue-600">$1</strong>');
    }

    selectLocation(item, input, hiddenInput, dropdown) {
        const locationId = item.getAttribute('data-location-id');
        const locationName = item.getAttribute('data-location-name');
        
        input.value = locationName;
        hiddenInput.value = locationId;
        
        // Add visual feedback
        input.style.borderColor = '#10b981';
        setTimeout(() => {
            input.style.borderColor = '#d1d5db';
        }, 1000);
        
        this.hideDropdown(dropdown);
        this.validateLocationFields();
    }

    handleKeyboardNavigation(e) {
        const items = this.activeDropdown.querySelectorAll('.autocomplete-item');
        
        if (items.length === 0) return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.selectedIndex = Math.min(this.selectedIndex + 1, items.length - 1);
                this.updateSelection(items);
                break;
                
            case 'ArrowUp':
                e.preventDefault();
                this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                this.updateSelection(items);
                break;
                
            case 'Enter':
                e.preventDefault();
                if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
                    items[this.selectedIndex].click();
                }
                break;
                
            case 'Escape':
                this.hideDropdown(this.activeDropdown);
                break;
        }
    }

    updateSelection(items) {
        items.forEach((item, index) => {
            if (index === this.selectedIndex) {
                item.classList.add('bg-blue-100');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('bg-blue-100');
            }
        });
    }

    hideDropdown(dropdown) {
        if (dropdown) {
            dropdown.style.display = 'none';
            this.activeDropdown = null;
            this.selectedIndex = -1;
        }
    }

    hideAllDropdowns() {
        const dropdowns = document.querySelectorAll('.autocomplete-dropdown');
        dropdowns.forEach(dropdown => this.hideDropdown(dropdown));
    }

    validateLocationFields() {
        // Check if both from and to locations are selected for the same row
        const rows = document.querySelectorAll('.rate-row');
        rows.forEach(row => {
            const fromInput = row.querySelector('input[name*="from_location"]:not([type="hidden"])');
            const toInput = row.querySelector('input[name*="to_location"]:not([type="hidden"])');
            const fromId = row.querySelector('input[name*="from_location_id"]');
            const toId = row.querySelector('input[name*="to_location_id"]');
            
            if (fromInput && toInput && fromId && toId) {
                if (fromId.value && toId.value) {
                    // Both locations selected, add success styling
                    fromInput.classList.add('border-green-500');
                    toInput.classList.add('border-green-500');
                    
                    setTimeout(() => {
                        fromInput.classList.remove('border-green-500');
                        toInput.classList.remove('border-green-500');
                    }, 2000);
                }
            }
        });
    }
}

// Initialize autocomplete system AFTER class definition
const locationAutocomplete = new LocationAutocomplete();

// Rate management variables
let rate20ftCount = 0;
let rate40ftCount = 0;

// Toggle container size sections
function toggleContainerSection(containerSize) {
    const checkbox = document.getElementById(`enable${containerSize}`);
    const section = document.getElementById(`rates${containerSize}Section`);
    const container = document.getElementById(`rates${containerSize}Container`);
    
    if (checkbox.checked) {
        section.style.display = 'block';
        // Add initial rate row if none exists
        if (container.children.length === 0) {
            if (containerSize === '20ft') {
                addRate20ft();
            } else {
                addRate40ft();
            }
        }
    } else {
        section.style.display = 'none';
        // Clear all rates for this container size
        container.innerHTML = '';
        if (containerSize === '20ft') {
            rate20ftCount = 0;
        } else {
            rate40ftCount = 0;
        }
    }
}

// Add 20ft rate row
function addRate20ft() {
    rate20ftCount++;
    const container = document.getElementById('rates20ftContainer');
    const rateRow = document.createElement('div');
    rateRow.className = 'rate-row p-4 border border-blue-200 rounded-lg bg-blue-25 mb-4';
    rateRow.setAttribute('data-rate-id', `20ft-${rate20ftCount}`);
    
    rateRow.innerHTML = `
        <div class="flex justify-between items-center mb-3">
            <h4 class="text-sm font-semibold text-blue-800">
                <i class="fas fa-shipping-fast mr-2"></i>
                20ft Rate #${rate20ftCount}
            </h4>
            <button type="button" onclick="removeRate20ft(this)" class="remove-rate inline-flex items-center px-2 py-1 border border-red-300 text-xs font-medium rounded text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none transition-colors" ${rate20ftCount === 1 ? 'style="display: none;"' : ''}>
                <i class="fas fa-trash mr-1"></i> Remove Rate
            </button>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <!-- Movement Type -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Movement Type *</label>
                <select name="rates_20ft[${rate20ftCount}][movement_type]" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500" onchange="handleMovementTypeChange(this)">
                    <option value="">Select Movement</option>
                    <option value="export">Export</option>
                    <option value="import">Import</option>
                    <option value="domestic">Domestic</option>
                    <option value="local">Local</option>
                </select>
            </div>

            <!-- Container Type -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Container Type *</label>
                <select name="rates_20ft[${rate20ftCount}][container_type]" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Select Type</option>
                    <option value="full">Full Container</option>
                    <option value="empty">Empty Container</option>
                </select>
            </div>

            <!-- From Location with Autocomplete -->
            <div class="location-field relative">
                <label class="block text-xs font-medium text-gray-700 mb-1">From Location <span class="location-required text-red-500" style="display: none;">*</span></label>
                <div class="relative">
                    <input type="text" 
                           name="rates_20ft[${rate20ftCount}][from_location]" 
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 location-input autocomplete-input"
                           placeholder="Start typing location..."
                           autocomplete="off"
                           data-field-type="from">
                    <input type="hidden" name="rates_20ft[${rate20ftCount}][from_location_id]" class="location-id-input">
                    <div class="autocomplete-dropdown absolute z-50 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-48 overflow-y-auto" style="display: none;"></div>
                </div>
            </div>

            <!-- To Location with Autocomplete -->
            <div class="location-field relative">
                <label class="block text-xs font-medium text-gray-700 mb-1">To Location <span class="location-required text-red-500" style="display: none;">*</span></label>
                <div class="relative">
                    <input type="text" 
                           name="rates_20ft[${rate20ftCount}][to_location]" 
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 location-input autocomplete-input"
                           placeholder="Start typing location..."
                           autocomplete="off"
                           data-field-type="to">
                    <input type="hidden" name="rates_20ft[${rate20ftCount}][to_location_id]" class="location-id-input">
                    <div class="autocomplete-dropdown absolute z-50 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-48 overflow-y-auto" style="display: none;"></div>
                </div>
            </div>

            <!-- Rate -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Rate (₹) *</label>
                <input type="number" name="rates_20ft[${rate20ftCount}][rate]" required min="0" step="0.01"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Enter rate">
            </div>
        </div>

        <!-- Additional Details Row -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Effective From</label>
                <input type="date" name="rates_20ft[${rate20ftCount}][effective_from]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Effective To</label>
                <input type="date" name="rates_20ft[${rate20ftCount}][effective_to]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
        </div>

        <div class="mt-4">
            <label class="block text-xs font-medium text-gray-700 mb-1">Remarks</label>
            <input type="text" name="rates_20ft[${rate20ftCount}][remarks]" 
                   class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                   placeholder="Any additional notes about this rate (e.g., 'Peak season rate', 'Bulk discount applicable')">
        </div>
    `;
    
    container.appendChild(rateRow);
    
    // Setup autocomplete for new inputs
    const newInputs = rateRow.querySelectorAll('.autocomplete-input');
    newInputs.forEach(input => {
        locationAutocomplete.setupAutocomplete(input);
    });
    
    // Update remove button visibility
    toggleRemoveButtons20ft();
    
    // Scroll to the new rate row
    rateRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    
    // Focus on the first input of the new row
    setTimeout(() => {
        const firstSelect = rateRow.querySelector('select[name*="movement_type"]');
        if (firstSelect) {
            firstSelect.focus();
        }
    }, 100);
    
    // Show success feedback
    showRateAddedFeedback('20ft', rate20ftCount);
}

// Add 40ft rate row
function addRate40ft() {
    rate40ftCount++;
    const container = document.getElementById('rates40ftContainer');
    const rateRow = document.createElement('div');
    rateRow.className = 'rate-row p-4 border border-green-200 rounded-lg bg-green-25 mb-4';
    rateRow.setAttribute('data-rate-id', `40ft-${rate40ftCount}`);
    
    rateRow.innerHTML = `
        <div class="flex justify-between items-center mb-3">
            <h4 class="text-sm font-semibold text-green-800">
                <i class="fas fa-shipping-fast mr-2"></i>
                40ft Rate #${rate40ftCount}
            </h4>
            <button type="button" onclick="removeRate40ft(this)" class="remove-rate inline-flex items-center px-2 py-1 border border-red-300 text-xs font-medium rounded text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none transition-colors" ${rate40ftCount === 1 ? 'style="display: none;"' : ''}>
                <i class="fas fa-trash mr-1"></i> Remove Rate
            </button>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <!-- Movement Type -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Movement Type *</label>
                <select name="rates_40ft[${rate40ftCount}][movement_type]" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500" onchange="handleMovementTypeChange(this)">
                    <option value="">Select Movement</option>
                    <option value="export">Export</option>
                    <option value="import">Import</option>
                    <option value="domestic">Domestic</option>
                    <option value="local">Local</option>
                </select>
            </div>

            <!-- Container Type -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Container Type *</label>
                <select name="rates_40ft[${rate40ftCount}][container_type]" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <option value="">Select Type</option>
                    <option value="full">Full Container</option>
                    <option value="empty">Empty Container</option>
                </select>
            </div>

            <!-- From Location with Autocomplete -->
            <div class="location-field relative">
                <label class="block text-xs font-medium text-gray-700 mb-1">From Location <span class="location-required text-red-500" style="display: none;">*</span></label>
                <div class="relative">
                    <input type="text" 
                           name="rates_40ft[${rate40ftCount}][from_location]" 
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500 location-input autocomplete-input"
                           placeholder="Start typing location..."
                           autocomplete="off"
                           data-field-type="from">
                    <input type="hidden" name="rates_40ft[${rate40ftCount}][from_location_id]" class="location-id-input">
                    <div class="autocomplete-dropdown absolute z-50 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-48 overflow-y-auto" style="display: none;"></div>
                </div>
            </div>

            <!-- To Location with Autocomplete -->
            <div class="location-field relative">
                <label class="block text-xs font-medium text-gray-700 mb-1">To Location <span class="location-required text-red-500" style="display: none;">*</span></label>
                <div class="relative">
                    <input type="text" 
                           name="rates_40ft[${rate40ftCount}][to_location]" 
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500 location-input autocomplete-input"
                           placeholder="Start typing location..."
                           autocomplete="off"
                           data-field-type="to">
                    <input type="hidden" name="rates_40ft[${rate40ftCount}][to_location_id]" class="location-id-input">
                    <div class="autocomplete-dropdown absolute z-50 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-48 overflow-y-auto" style="display: none;"></div>
                </div>
            </div>

            <!-- Rate -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Rate (₹) *</label>
                <input type="number" name="rates_40ft[${rate40ftCount}][rate]" required min="0" step="0.01"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500"
                       placeholder="Enter rate">
            </div>
        </div>

        <!-- Additional Details Row -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Effective From</label>
                <input type="date" name="rates_40ft[${rate40ftCount}][effective_from]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Effective To</label>
                <input type="date" name="rates_40ft[${rate40ftCount}][effective_to]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>
        </div>

        <div class="mt-4">
            <label class="block text-xs font-medium text-gray-700 mb-1">Remarks</label>
            <input type="text" name="rates_40ft[${rate40ftCount}][remarks]" 
                   class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500"
                   placeholder="Any additional notes about this rate (e.g., 'Premium service', 'Express delivery')">
        </div>
    `;
    
    container.appendChild(rateRow);
    
    // Setup autocomplete for new inputs
    const newInputs = rateRow.querySelectorAll('.autocomplete-input');
    newInputs.forEach(input => {
        locationAutocomplete.setupAutocomplete(input);
    });
    
    // Update remove button visibility
    toggleRemoveButtons40ft();
    
    // Scroll to the new rate row
    rateRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    
    // Focus on the first input of the new row
    setTimeout(() => {
        const firstSelect = rateRow.querySelector('select[name*="movement_type"]');
        if (firstSelect) {
            firstSelect.focus();
        }
    }, 100);
    
    // Show success feedback
    showRateAddedFeedback('40ft', rate40ftCount);
}

// Remove rate functions
function removeRate20ft(button) {
    const rateRow = button.closest('.rate-row');
    
    if (confirm('Are you sure you want to remove this 20ft rate? This action cannot be undone.')) {
        rateRow.style.transition = 'all 0.3s ease';
        rateRow.style.opacity = '0';
        rateRow.style.transform = 'translateX(-100%)';
        
        setTimeout(() => {
            rateRow.remove();
            rate20ftCount--;
            toggleRemoveButtons20ft();
            renumberRates('20ft');
            showRateRemovedFeedback('20ft');
        }, 300);
    }
}

function removeRate40ft(button) {
    const rateRow = button.closest('.rate-row');
    
    if (confirm('Are you sure you want to remove this 40ft rate? This action cannot be undone.')) {
        rateRow.style.transition = 'all 0.3s ease';
        rateRow.style.opacity = '0';
        rateRow.style.transform = 'translateX(-100%)';
        
        setTimeout(() => {
            rateRow.remove();
            rate40ftCount--;
            toggleRemoveButtons40ft();
            renumberRates('40ft');
            showRateRemovedFeedback('40ft');
        }, 300);
    }
}

// Toggle remove buttons visibility
function toggleRemoveButtons20ft() {
    const removeButtons = document.querySelectorAll('#rates20ftContainer .remove-rate');
    const container = document.getElementById('rates20ftContainer');
    const rateCount = container.children.length;
    
    removeButtons.forEach(button => {
        button.style.display = rateCount > 1 ? 'inline-flex' : 'none';
    });
    
    updateAddButtonText('20ft', rateCount);
}

function toggleRemoveButtons40ft() {
    const removeButtons = document.querySelectorAll('#rates40ftContainer .remove-rate');
    const container = document.getElementById('rates40ftContainer');
    const rateCount = container.children.length;
    
    removeButtons.forEach(button => {
        button.style.display = rateCount > 1 ? 'inline-flex' : 'none';
    });
    
    updateAddButtonText('40ft', rateCount);
}

// Renumber rates after removal
function renumberRates(containerSize) {
    const container = document.getElementById(`rates${containerSize}Container`);
    const rateRows = container.querySelectorAll('.rate-row');
    
    rateRows.forEach((row, index) => {
        const rateNumber = index + 1;
        const header = row.querySelector('h4');
        if (header) {
            header.innerHTML = `<i class="fas fa-shipping-fast mr-2"></i>${containerSize} Rate #${rateNumber}`;
        }
        row.setAttribute('data-rate-id', `${containerSize}-${rateNumber}`);
    });
}

// Update add button text
function updateAddButtonText(containerSize, count) {
    const addButton = document.getElementById(`add${containerSize}Rate`);
    if (addButton) {
        if (count === 0) {
            addButton.innerHTML = '<i class="fas fa-plus mr-1"></i> Add Rate';
        } else {
            addButton.innerHTML = `<i class="fas fa-plus mr-1"></i> Add Another Rate (${count + 1})`;
        }
    }
}

// Success feedback functions
function showRateAddedFeedback(containerSize, rateNumber) {
    const message = `${containerSize} Rate #${rateNumber} added successfully!`;
    showNotificationToast('success', message);
}

function showRateRemovedFeedback(containerSize) {
    const message = `${containerSize} rate removed successfully!`;
    showNotificationToast('info', message);
}

// Notification toast system
function showNotificationToast(type, message) {
    const existingToasts = document.querySelectorAll('.rate-toast');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `rate-toast fixed top-4 right-4 z-50 p-3 rounded-lg shadow-lg max-w-sm animate-slide-in ${
        type === 'success' ? 'bg-green-100 border-l-4 border-green-500 text-green-700' :
        type === 'info' ? 'bg-blue-100 border-l-4 border-blue-500 text-blue-700' :
        'bg-red-100 border-l-4 border-red-500 text-red-700'
    }`;
    
    toast.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${
                type === 'success' ? 'fa-check-circle' :
                type === 'info' ? 'fa-info-circle' :
                'fa-exclamation-circle'
            } mr-2"></i>
            <span class="text-sm font-medium">${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-3 text-gray-500 hover:text-gray-700">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        if (toast.parentNode) {
            toast.style.animation = 'slide-out 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }
    }, 3000);
}

// Handle movement type changes
function handleMovementTypeChange(selectElement) {
    const rateRow = selectElement.closest('.rate-row');
    const locationFields = rateRow.querySelectorAll('.location-field');
    const locationInputs = rateRow.querySelectorAll('.location-input');
    const locationRequired = rateRow.querySelectorAll('.location-required');
    const movementType = selectElement.value;
    
    // Show location requirement for long distance movements
    const requiresLocation = ['export', 'import', 'domestic'].includes(movementType);
    
    locationRequired.forEach(span => {
        span.style.display = requiresLocation ? 'inline' : 'none';
    });
    
    locationInputs.forEach(input => {
        if (requiresLocation) {
            input.setAttribute('required', '');
            input.style.borderColor = '#d1d5db';
            input.style.backgroundColor = '#ffffff';
            input.removeAttribute('disabled');
        } else {
            input.removeAttribute('required');
            input.value = ''; // Clear location values for local movements
            input.style.borderColor = '#e5e7eb';
            input.style.backgroundColor = '#f9fafb';
        }
    });
    
    // Clear hidden location IDs when movement type changes
    const hiddenInputs = rateRow.querySelectorAll('.location-id-input');
    hiddenInputs.forEach(hidden => {
        if (!requiresLocation) {
            hidden.value = '';
        }
    });
    
    // Add visual feedback
    locationFields.forEach(field => {
        if (requiresLocation) {
            field.style.opacity = '1';
            field.classList.remove('opacity-50');
        } else {
            field.style.opacity = '0.6';
            field.classList.add('opacity-50');
        }
    });
}

// Initialize event listeners when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Billing address formatting and validation
    const billingAddressInput = document.getElementById('billing_address');
    if (billingAddressInput) {
        billingAddressInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
        
        billingAddressInput.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value) {
                const formatted = value.split('\n').map(line => 
                    line.charAt(0).toUpperCase() + line.slice(1).toLowerCase()
                ).join('\n');
                this.value = formatted;
            }
        });
    }
    
    // Enhanced PAN number formatting
    const panInput = document.getElementById('pan_number');
    if (panInput) {
        panInput.addEventListener('input', function() {
            let value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            
            if (value.length > 10) {
                value = value.substring(0, 10);
            }
            
            this.value = value;
            
            const isValid = /^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(value);
            if (value.length === 10) {
                if (isValid) {
                    this.style.borderColor = '#10b981';
                    this.style.backgroundColor = '#f0fdf4';
                } else {
                    this.style.borderColor = '#ef4444';
                    this.style.backgroundColor = '#fef2f2';
                }
            } else {
                this.style.borderColor = '#d1d5db';
                this.style.backgroundColor = '#ffffff';
            }
        });
    }
    
    // Enhanced GST number formatting
    const gstInput = document.getElementById('gst_number');
    if (gstInput) {
        gstInput.addEventListener('input', function() {
            let value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            
            if (value.length > 15) {
                value = value.substring(0, 15);
            }
            
            this.value = value;
            
            if (value.length === 15) {
                const isValidFormat = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}[Z]{1}[0-9A-Z]{1}$/.test(value);
                if (isValidFormat) {
                    this.style.borderColor = '#10b981';
                    this.style.backgroundColor = '#f0fdf4';
                } else {
                    this.style.borderColor = '#ef4444';
                    this.style.backgroundColor = '#fef2f2';
                }
            } else {
                this.style.borderColor = '#d1d5db';
                this.style.backgroundColor = '#ffffff';
            }
        });
    }
    
    // Billing cycle selection enhancement
    const billingCycleSelect = document.getElementById('billing_cycle_days');
    if (billingCycleSelect) {
        billingCycleSelect.addEventListener('change', function() {
            const value = parseInt(this.value);
            const helpText = this.parentElement.querySelector('.text-xs');
            
            if (helpText) {
                if (value <= 7) {
                    helpText.textContent = 'Weekly payment cycle - frequent invoicing';
                    helpText.className = 'text-xs text-blue-600 mt-1';
                } else if (value <= 30) {
                    helpText.textContent = 'Monthly payment cycle - standard terms';
                    helpText.className = 'text-xs text-green-600 mt-1';
                } else if (value <= 60) {
                    helpText.textContent = 'Extended payment terms - requires approval';
                    helpText.className = 'text-xs text-amber-600 mt-1';
                } else {
                    helpText.textContent = 'Long-term payment cycle - special arrangements';
                    helpText.className = 'text-xs text-red-600 mt-1';
                }
            }
        });
    }
    
    // Add button listeners
    const add20ftBtn = document.getElementById('add20ftRate');
    const add40ftBtn = document.getElementById('add40ftRate');
    
    if (add20ftBtn) {
        add20ftBtn.addEventListener('click', addRate20ft);
    }
    
    if (add40ftBtn) {
        add40ftBtn.addEventListener('click', addRate40ft);
    }
    
    // Update button texts initially
    updateAddButtonText('20ft', 0);
    updateAddButtonText('40ft', 0);
    
    // Enhanced form validation
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const billingAddress = document.getElementById('billing_address');
            const pan = document.getElementById('pan_number');
            const gst = document.getElementById('gst_number');
            
            // Billing address validation
            if (billingAddress && billingAddress.value.trim().length < 10) {
                e.preventDefault();
                alert('Billing address must be at least 10 characters long.');
                billingAddress.focus();
                return false;
            }
            
            // PAN validation (if provided)
            if (pan && pan.value.trim() && !/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pan.value.trim())) {
                e.preventDefault();
                alert('Invalid PAN format. Please use format: ABCDE1234F');
                pan.focus();
                return false;
            }
            
            // GST validation (if provided)
            if (gst && gst.value.trim() && (gst.value.trim().length !== 15 || !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}[Z]{1}[0-9A-Z]{1}$/.test(gst.value.trim()))) {
                e.preventDefault();
                alert('Invalid GST format. Please enter a valid 15-character GST number.');
                gst.focus();
                return false;
            }
            
            // Rate validation for movement types requiring locations
            let hasValidRates = true;
            const allRateRows = document.querySelectorAll('.rate-row');
            
            for (let row of allRateRows) {
                const movementType = row.querySelector('select[name*="movement_type"]').value;
                const containerType = row.querySelector('select[name*="container_type"]').value;
                const rate = row.querySelector('input[name*="rate"]').value;
                const fromLocation = row.querySelector('input[name*="from_location"]').value;
                const toLocation = row.querySelector('input[name*="to_location"]').value;
                
                if (movementType && containerType && rate) {
                    // Check location requirement for long distance movements
                    if (['export', 'import', 'domestic'].includes(movementType)) {
                        if (!fromLocation.trim() || !toLocation.trim()) {
                            e.preventDefault();
                            alert(`From and To locations are mandatory for ${movementType} movements.`);
                            return false;
                        }
                    }
                }
            }
        });
    }
});

// CSS animations for toast notifications
const toastStyles = document.createElement('style');
toastStyles.textContent = `
@keyframes slide-in {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slide-out {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}

.animate-slide-in {
    animation: slide-in 0.3s ease forwards;
}

.rate-row {
    transition: all 0.3s ease;
}

.rate-row:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.bg-blue-25 {
    background-color: rgba(59, 130, 246, 0.05);
}

.bg-green-25 {
    background-color: rgba(34, 197, 94, 0.05);
}

.location-field {
    transition: opacity 0.3s ease;
}

.autocomplete-dropdown {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    z-index: 1000;
}

.autocomplete-item {
    transition: background-color 0.15s ease;
}

.autocomplete-item:hover {
    background-color: #eff6ff;
}

.autocomplete-item.bg-blue-100 {
    background-color: #dbeafe;
}

.autocomplete-input:focus {
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
`;
document.head.appendChild(toastStyles);
</script>
</body>
</html>


                 