-- ChatApp Database Schema
-- Generated from live database structure (20 tables, 2 triggers)

CREATE DATABASE IF NOT EXISTS chatapp
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE chatapp;

-- ----------------------------------------------------------------------
-- Users
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(20) NOT NULL,
    display_name VARCHAR(256) DEFAULT NULL,
    preferred_language VARCHAR(10) NOT NULL DEFAULT 'en',
    password VARCHAR(255) NOT NULL,
    avatar LONGTEXT DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    searchable TINYINT(1) NOT NULL DEFAULT 1,
    searchable_by_uid TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    custom_title TEXT DEFAULT NULL,
    timezone VARCHAR(8) NOT NULL DEFAULT '+08:00',
    data_saver TINYINT(1) NOT NULL DEFAULT 0,
    dnd TINYINT(1) NOT NULL DEFAULT 0,
    last_login DATETIME DEFAULT NULL,
    placeholder TINYINT(1) NOT NULL DEFAULT 0,
    restricted TINYINT(1) NOT NULL DEFAULT 0,
    token_reset DATETIME DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    restricted_reason TEXT DEFAULT NULL,
    last_ping DATETIME DEFAULT NULL,
    typing_to VARCHAR(20) DEFAULT NULL,
    typing_at DATETIME DEFAULT NULL,
    emoji_panel_mode VARCHAR(10) NOT NULL DEFAULT 'dynamic',
    emoji_chat_mode VARCHAR(10) NOT NULL DEFAULT 'dynamic',
    exp INT NOT NULL DEFAULT 0,
    last_exp_msg_at DATETIME DEFAULT NULL,
    last_exp_attach_at DATETIME DEFAULT NULL,
    last_sign_date DATE DEFAULT NULL,
    sign_streak INT NOT NULL DEFAULT 0,
    last_exp_bug_at DATETIME DEFAULT NULL,
    last_exp_suggestion_at DATETIME DEFAULT NULL,
    level INT NOT NULL DEFAULT 1,
    bg_image VARCHAR(255) DEFAULT NULL,
    bg_updated_at DATETIME DEFAULT NULL,
    gender TINYINT(1) DEFAULT NULL,
    gender_privacy TINYINT(1) NOT NULL DEFAULT 0,
    bg_privacy TINYINT(1) NOT NULL DEFAULT 0,
    bg_blacklist TEXT DEFAULT NULL,
    bg_whitelist TEXT DEFAULT NULL,
    bg_no_friend TINYINT(1) NOT NULL DEFAULT 0,
    bg_private_image VARCHAR(255) DEFAULT NULL,
    profile_bg_image VARCHAR(255) DEFAULT NULL,
    profile_bg_updated_at DATETIME DEFAULT NULL,
    birthday DATE DEFAULT NULL,
    duress_password VARCHAR(255) DEFAULT NULL,
    cache_key VARCHAR(88) DEFAULT NULL,
    local_cache_enabled TINYINT(1) NOT NULL DEFAULT 0,
    pin_self TINYINT(1) NOT NULL DEFAULT 1,
    notif_system TINYINT(1) NOT NULL DEFAULT 1,
    notif_banner TINYINT(1) NOT NULL DEFAULT 1,
    typing_visible TINYINT(1) NOT NULL DEFAULT 1,
    stranger_invite_group TINYINT(1) NOT NULL DEFAULT 1,
    stranger_like TINYINT(1) NOT NULL DEFAULT 1,
    anyone_add_friend TINYINT(1) NOT NULL DEFAULT 1,
    likes INT NOT NULL DEFAULT 0,
    auto_focus_input TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id),
    UNIQUE KEY username (username),
    UNIQUE KEY user_id (user_id),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB AUTO_INCREMENT=10000 DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Messages
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id INT NOT NULL AUTO_INCREMENT,
    sender_id INT NOT NULL DEFAULT 0,
    recipient_id INT DEFAULT NULL,
    message TEXT NOT NULL,
    msg_type VARCHAR(10) DEFAULT NULL,
    attachment TEXT DEFAULT NULL,
    time VARCHAR(19) NOT NULL,
    datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    read_at DATETIME DEFAULT NULL,
    group_id INT DEFAULT NULL,
    reply_to INT DEFAULT NULL,
    temp_upload_id INT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_id (id),
    KEY idx_read (read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Contacts
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contacts (
    id INT NOT NULL AUTO_INCREMENT,
    user_from INT UNSIGNED NOT NULL,
    user_to INT UNSIGNED NOT NULL,
    status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    msg TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note TEXT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_user_from (user_from),
    KEY idx_user_to (user_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Groups
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `groups` (
    id INT NOT NULL AUTO_INCREMENT,
    group_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    owner_id INT NOT NULL,
    public TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY group_id (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS group_members (
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
    muted TINYINT(1) NOT NULL DEFAULT 0,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS group_requests (
    id INT NOT NULL AUTO_INCREMENT,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_req (group_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Incidents (bug reports / support tickets)
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS incidents (
    id INT NOT NULL AUTO_INCREMENT,
    type VARCHAR(20) NOT NULL DEFAULT 'bug',
    reporter_id INT NOT NULL,
    target_id INT DEFAULT NULL,
    subject VARCHAR(500) DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    message_ids TEXT DEFAULT NULL,
    status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    priority ENUM('task','low','normal','medium','high','urgent','critical','nopriority') NOT NULL DEFAULT 'normal',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    images TEXT DEFAULT NULL,
    exp_awarded TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_incidents_status (status),
    KEY idx_incidents_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS incident_responses (
    id INT NOT NULL AUTO_INCREMENT,
    incident_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ir_incident (incident_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Reports
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reports (
    id INT NOT NULL AUTO_INCREMENT,
    reporter_id INT NOT NULL,
    target_id INT NOT NULL,
    reason TEXT DEFAULT NULL,
    message_ids TEXT DEFAULT NULL,
    status ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reports_target (target_id),
    KEY idx_reports_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Emoji
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS custom_emoji (
    id INT NOT NULL AUTO_INCREMENT,
    owner_uid INT NOT NULL,
    hash CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_custom_emoji (owner_uid, hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS public_emoji (
    id INT NOT NULL AUTO_INCREMENT,
    owner_uid INT NOT NULL,
    hash CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    filename VARCHAR(255) DEFAULT NULL,
    size INT DEFAULT 0,
    content_type VARCHAR(100) DEFAULT NULL,
    ext VARCHAR(20) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY owner_uid (owner_uid, hash),
    KEY idx_owner (owner_uid),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Donations
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS donations (
    id INT NOT NULL AUTO_INCREMENT,
    datetime DATETIME NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(20) NOT NULL,
    display_name VARCHAR(256) NOT NULL,
    weixin_id VARCHAR(64) DEFAULT NULL,
    qq VARCHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_donations_datetime (datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Temp uploads
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS temp_uploads (
    id INT NOT NULL AUTO_INCREMENT,
    hash CHAR(64) NOT NULL,
    owner_uid INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    size BIGINT NOT NULL,
    ext VARCHAR(20) DEFAULT NULL,
    message_id INT DEFAULT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    last_download_at DATETIME DEFAULT NULL,
    download_started_at DATETIME DEFAULT NULL,
    downloaded_bytes BIGINT NOT NULL DEFAULT 0,
    download_complete TINYINT(1) NOT NULL DEFAULT 0,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY hash (hash),
    KEY idx_temp_owner (owner_uid),
    KEY idx_temp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Level / EXP system
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exp_log (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    exp INT NOT NULL,
    type VARCHAR(30) NOT NULL,
    detail VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exp_log_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS exp_bonus (
    user_id INT NOT NULL,
    bonus_key VARCHAR(30) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, bonus_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS daily_counters (
    user_id INT NOT NULL,
    ddate DATE NOT NULL,
    ctype VARCHAR(20) NOT NULL,
    cnt INT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, ddate, ctype)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Role definitions & permissions
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS role_defs (
    role_name VARCHAR(20) NOT NULL,
    permissions JSON NOT NULL,
    editable TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default roles
INSERT IGNORE INTO role_defs (role_name, permissions, editable) VALUES
('root', JSON_OBJECT(
    'announcements', JSON_ARRAY('send'),
    'reports', JSON_ARRAY('view','resolve'),
    'users', JSON_ARRAY('view','edit_role','delete','change_password','login_as','add_user','send_friend_request'),
    'support', JSON_ARRAY('respond'),
    'settings', JSON_ARRAY('view','edit')
), 0),
('admin', JSON_OBJECT(
    'announcements', JSON_ARRAY('send'),
    'reports', JSON_ARRAY('view','resolve'),
    'users', JSON_ARRAY('view','edit_role','delete','change_password','login_as','add_user','send_friend_request'),
    'support', JSON_ARRAY('respond'),
    'settings', JSON_ARRAY('view','edit')
), 1),
('user', JSON_OBJECT(
    'chat', JSON_ARRAY('send'),
    'contacts', JSON_ARRAY('manage'),
    'support', JSON_ARRAY('respond')
), 1),
('none', JSON_OBJECT(), 1);

-- ----------------------------------------------------------------------
-- Audit / security logs
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_logs (
    id INT NOT NULL AUTO_INCREMENT,
    admin_uid INT NOT NULL,
    admin_username VARCHAR(20) NOT NULL,
    action VARCHAR(50) NOT NULL,
    target_uid INT DEFAULT NULL,
    target_username VARCHAR(20) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_logs_admin (admin_uid),
    KEY idx_admin_logs_target (target_username),
    KEY idx_admin_logs_action (action),
    KEY idx_admin_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_logs (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL DEFAULT 0,
    username VARCHAR(20) NOT NULL DEFAULT '',
    success TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_logs_user (user_id),
    KEY idx_login_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS security_logs (
    id INT NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(30) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    target_path VARCHAR(500) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sec_type (event_type),
    KEY idx_sec_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------
-- Triggers (block deletion of immutable audit logs)
-- ----------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_block_admin_logs_delete;
DELIMITER $$
CREATE TRIGGER trg_block_admin_logs_delete
BEFORE DELETE ON admin_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'admin_logs deletion is forbidden';
$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_block_login_logs_delete;
DELIMITER $$
CREATE TRIGGER trg_block_login_logs_delete
BEFORE DELETE ON login_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'login_logs deletion is forbidden';
$$
DELIMITER ;