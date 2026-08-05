<?php
/**
 * ChatApp - Modern entry redirect
 */
require_once __DIR__ . '/../api/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['username'])) {
    header('Location: chat.php');
} else {
    header('Location: login.php');
}
exit;