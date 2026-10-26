<?php
// Friendly root-level login entry point.
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: pages/PLANNING.php?tab=prd_pending');
} else {
    header('Location: pages/LOGIN.php');
}
exit;
