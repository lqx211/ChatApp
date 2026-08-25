<?php
/**
 * ChatApp - Modern entry redirect
 */
require_once __DIR__ . '/../../api/config.php';

chatapp_session_start();

if (isset($_SESSION['username'])) {
    header('Location: chat.php');
} else {
    header('Location: login.php');
}
exit;