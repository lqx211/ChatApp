<?php
/**
 * ChatApp - Settings redirect (settings now integrated into chat)
 */
require_once __DIR__ . '/../api/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
} else {
    header('Location: chat.php#more');
}
exit;