-- ChatApp Database Schema

CREATE DATABASE IF NOT EXISTS chatapp
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE chatapp;

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(20) NOT NULL UNIQUE,
    display_name VARCHAR(256) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    preferred_language VARCHAR(10) NOT NULL DEFAULT 'en',
    avatar LONGTEXT DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    searchable TINYINT(1) NOT NULL DEFAULT 1,
    searchable_by_uid TINYINT(1) NOT NULL DEFAULT 1,
    custom_title TEXT DEFAULT NULL,
    timezone VARCHAR(8) NOT NULL DEFAULT '+08:00',
    cache_key VARCHAR(88) DEFAULT NULL,
    local_cache_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL DEFAULT 0,
    recipient_id INT DEFAULT NULL,
    message TEXT NOT NULL,
    msg_type VARCHAR(10) DEFAULT NULL,
    attachment TEXT DEFAULT NULL,
    time VARCHAR(19) NOT NULL,
    datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    INDEX idx_id (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_from INT NOT NULL,
    user_to INT NOT NULL,
    status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    msg TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_request (user_from, user_to),
    INDEX idx_user_from (user_from),
    INDEX idx_user_to (user_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;