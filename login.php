<?php
// Friendly root-level login entry point.
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: pages/INDEX.php');
} else {
    header('Location: pages/LOGIN.php');
}
exit;
