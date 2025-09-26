<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

if (!in_array($_SESSION['role'] ?? '', ['manager1','admin','superadmin'])) {
    header('Location: ../dashboard.php');
    exit();
}

// Simple CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $token = $_POST['csrf'] ?? '';
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    $result = ['success' => false];
    if ($id > 0 && hash_equals($_SESSION['csrf'], $token)) {
        try {
            // First, get the booking details to check status and PDF
            $getStmt = $pdo->prepare('SELECT booking_receipt_pdf, status FROM bookings WHERE id = ?');
            $getStmt->execute([$id]);
            $booking = $getStmt->fetch();
            
            // Check if booking is confirmed and user is not admin
            if ($booking && $booking['status'] === 'confirmed' && $_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin') {
                $result['message'] = 'Cannot delete confirmed bookings (Admin only)';
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode($result);
                    exit();
                }
                header('Location: manage.php?error=confirmed_booking_restricted');
                exit();
            }
            
            // If booking had a PDF file, delete it from filesystem first
            $pdfDeleted = false;
            if ($booking && !empty($booking['booking_receipt_pdf'])) {
                $pdfPath = __DIR__ . '/../Uploads/booking_docs/' . $booking['booking_receipt_pdf'];
                error_log("Attempting to delete PDF: " . $pdfPath);
                error_log("PDF filename from database: " . $booking['booking_receipt_pdf']);
                
                // Check if directory is writable
                $uploadDir = __DIR__ . '/../Uploads/booking_docs/';
                if (!is_writable($uploadDir)) {
                    error_log("Upload directory is not writable: " . $uploadDir);
                }
                
                if (file_exists($pdfPath)) {
                    if (unlink($pdfPath)) {
                        error_log("Successfully deleted PDF: " . $pdfPath);
                        $pdfDeleted = true;
                    } else {
                        error_log("Failed to delete PDF file: " . $pdfPath);
                        error_log("File permissions: " . substr(sprintf('%o', fileperms($pdfPath)), -4));
                    }
                } else {
                    error_log("PDF file does not exist: " . $pdfPath);
                    $pdfDeleted = true; // Consider it deleted if it doesn't exist
                }
            } else {
                error_log("No PDF file to delete for booking ID: " . $id);
                if ($booking) {
                    error_log("Booking data: " . print_r($booking, true));
                }
                $pdfDeleted = true; // No PDF to delete
            }
            
            // Delete the booking from database
            $stmt = $pdo->prepare('DELETE FROM bookings WHERE id = ?');
            $stmt->execute([$id]);
            
            $result['success'] = true;
        } catch (Exception $e) {
            $result['success'] = false;
            $result['message'] = 'Delete failed: ' . $e->getMessage();
        }
    } else {
        $result['message'] = 'Invalid request';
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($result);
        exit();
    }
    header('Location: manage.php');
    exit();
}

// GET fallback: show confirm screen
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: manage.php'); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Delete Booking</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="gradient-bg overflow-x-hidden">
  <div class="min-h-screen">
    <?php include '../header_component.php'; ?>
    <div class="max-w-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="bg-white rounded-xl shadow p-6 border-l-4 border-red-600">
        <h1 class="text-xl font-bold text-red-700 mb-4"><i class="fas fa-triangle-exclamation mr-2"></i>Confirm Delete</h1>
        <p class="text-gray-700 mb-6">Are you sure you want to delete this booking? This action cannot be undone.</p>
        <form method="POST" class="flex gap-3">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
          <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">Delete</button>
          <a href="manage.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
