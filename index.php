<?php
// Main application entry point for Apache/XAMPP.
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: pages/INDEX.php');
} else {
    header('Location: pages/LOGIN.php');
}
exit;
