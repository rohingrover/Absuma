<?php
// header_component.php - Reusable header with Absuma branding
$page_title = $page_title ?? '';
$page_subtitle = $page_subtitle ?? '';
?>

<header class="bg-white border-b border-gray-200 shadow-sm">
    <div class="max-w-[1340px] mx-auto px-6 py-4">
        <div class="flex justify-between items-center">
            <!-- Logo and Company Info -->
            <div class="flex items-center gap-4">
                <!-- Company Logo/Icon -->
                <div class="flex items-center gap-3">
                    <div class="bg-red-600 p-3 rounded-lg text-white shadow-md">
                        <i class="fas fa-truck-pickup text-xl"></i>
                    </div>
                    <div class="hidden md:block">
                        <div class="text-2xl font-bold text-gray-800 leading-tight">
                            <span class="text-red-600">ABSUMA</span> 
                            <span class="text-gray-700">Logistics India Pvt. Ltd.</span>
                        </div>
                        <div class="text-sm text-gray-600 font-medium">
                            Transportation • Fumigation • FHAT • WPM
                        </div>
                    </div>
                    <div class="md:hidden">
                        <div class="text-lg font-bold text-red-600">ABSUMA</div>
                        <div class="text-xs text-gray-600">Logistics</div>
                    </div>
                </div>
                
                <!-- Page Title -->
                <div class="hidden lg:block border-l border-gray-300 pl-4 ml-2">
                    <h1 class="text-xl font-semibold text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
                    <?php if ($page_subtitle): ?>
                        <p class="text-blue-600 text-sm"><?= htmlspecialchars($page_subtitle) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- User Info and Actions -->
            <div class="flex items-center gap-4">
                <!-- User Profile -->
                <div class="hidden sm:flex items-center gap-3">
                    <div class="bg-blue-50 px-4 py-2 rounded-lg border border-blue-100">
                        <div class="text-blue-800 font-medium text-sm">
                            <i class="fas fa-user-circle mr-2"></i>
                            <?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?>
                        </div>
                        <div class="text-blue-600 text-xs">
                            <?= htmlspecialchars($_SESSION['role'] ?? 'Staff') ?>
                        </div>
                    </div>
                </div>
                
                <!-- Mobile User Icon -->
                <div class="sm:hidden">
                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-blue-600"></i>
                    </div>
                </div>

                <!-- Notifications -->
                <?php if (file_exists('notification_bell.php')): ?>
                    <?php include 'notification_bell.php'; ?>
                <?php else: ?>
                    <button class="text-blue-600 hover:text-blue-800 p-2 relative">
                        <i class="fas fa-bell text-lg"></i>
                        <span class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full">3</span>
                    </button>
                <?php endif; ?>

                <!-- Logout -->
                <a href="logout.php" class="text-red-600 hover:text-red-800 p-2 transition-colors" title="Logout">
                    <i class="fas fa-sign-out-alt text-lg"></i>
                </a>
            </div>
        </div>
        
        <!-- Mobile Page Title -->
        <div class="lg:hidden mt-3 pt-3 border-t border-gray-200">
            <h1 class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
            <?php if ($page_subtitle): ?>
                <p class="text-blue-600 text-sm"><?= htmlspecialchars($page_subtitle) ?></p>
            <?php endif; ?>
        </div>
    </div>
</header>

<style>
/* Additional styles for the header component */
.header-logo-animation {
    animation: logoFloat 3s ease-in-out infinite;
}

@keyframes logoFloat {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-2px); }
}

.company-name-gradient {
    background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Responsive improvements */
@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        gap: 1rem;
    }
}
</style>