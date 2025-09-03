<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// You can add role-based checks here if needed
// if ($_SESSION['role'] != 'admin') {
//     header("Location: unauthorized.php");
//     exit();
// }
?>