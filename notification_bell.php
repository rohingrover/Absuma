<?php
	
require 'auth_check.php';
require 'db_connection.php';
?>

<div class="relative">
    <button id="notificationBell" class="text-blue-600 hover:text-blue-800 p-2">
        <i class="fas fa-bell text-lg"></i>
        <span id="notificationCount" class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full" style="display: none;"></span>
    </button>
    <div id="notificationDropdown" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-md shadow-lg z-10 border border-gray-200">
        <div class="py-2">
            <div class="flex justify-between items-center px-4 py-2 border-b border-gray-200">
                <span class="text-sm font-medium text-gray-900">Notifications</span>
                <button id="clearAllNotifications" class="text-xs text-red-600 hover:text-red-800">Clear All</button>
            </div>
            <div id="notificationList" class="max-h-64 overflow-y-auto"></div>
        </div>
    </div>
</div>