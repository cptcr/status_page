-- Status Page Database Schema with Proxmox Integration

-- Create database
CREATE DATABASE IF NOT EXISTS status_page CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE status_page;

-- Domain monitoring table
CREATE TABLE IF NOT EXISTS domain_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    url VARCHAR(255) NOT NULL,
    status ENUM('operational', 'degraded', 'down') NOT NULL,
    response_time INT DEFAULT NULL,
    status_code INT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name_checked (name, checked_at),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Server monitoring table (for non-Proxmox servers)
CREATE TABLE IF NOT EXISTS server_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT NOT NULL,
    type ENUM('minecraft', 'external', 'system') NOT NULL,
    status ENUM('operational', 'degraded', 'down') NOT NULL,
    response_time INT DEFAULT NULL,
    players_online INT DEFAULT NULL,
    max_players INT DEFAULT NULL,
    server_version VARCHAR(100) DEFAULT NULL,
    cpu_usage DECIMAL(5,2) DEFAULT NULL,
    memory_usage DECIMAL(5,2) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name_checked (name, checked_at),
    INDEX idx_type_status (type, status),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Proxmox nodes monitoring
CREATE TABLE IF NOT EXISTS proxmox_nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status ENUM('operational', 'degraded', 'down') NOT NULL,
    cpu_usage DECIMAL(5,2) DEFAULT NULL,
    memory_usage DECIMAL(5,2) DEFAULT NULL,
    disk_usage DECIMAL(5,2) DEFAULT NULL,
    uptime BIGINT DEFAULT NULL,
    load_average JSON DEFAULT NULL,
    memory_total BIGINT DEFAULT NULL,
    memory_used BIGINT DEFAULT NULL,
    disk_total BIGINT DEFAULT NULL,
    disk_used BIGINT DEFAULT NULL,
    network_in BIGINT DEFAULT 0,
    network_out BIGINT DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name_checked (name, checked_at),
    INDEX idx_status (status),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Proxmox VMs monitoring
CREATE TABLE IF NOT EXISTS proxmox_vms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_name VARCHAR(100) NOT NULL,
    vmid INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    status ENUM('operational', 'degraded', 'down') NOT NULL,
    vm_status VARCHAR(50) DEFAULT NULL,
    cpu_usage DECIMAL(5,2) DEFAULT NULL,
    memory_usage DECIMAL(5,2) DEFAULT NULL,
    memory_used BIGINT DEFAULT NULL,
    memory_max BIGINT DEFAULT NULL,
    disk_read BIGINT DEFAULT 0,
    disk_write BIGINT DEFAULT 0,
    network_in BIGINT DEFAULT 0,
    network_out BIGINT DEFAULT 0,
    uptime BIGINT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_node_vmid_checked (node_name, vmid, checked_at),
    INDEX idx_status (status),
    INDEX idx_checked_at (checked_at),
    UNIQUE KEY unique_check (node_name, vmid, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Proxmox Containers (LXC) monitoring
CREATE TABLE IF NOT EXISTS proxmox_containers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_name VARCHAR(100) NOT NULL,
    vmid INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    status ENUM('operational', 'degraded', 'down') NOT NULL,
    container_status VARCHAR(50) DEFAULT NULL,
    cpu_usage DECIMAL(5,2) DEFAULT NULL,
    memory_usage DECIMAL(5,2) DEFAULT NULL,
    memory_used BIGINT DEFAULT NULL,
    memory_max BIGINT DEFAULT NULL,
    disk_read BIGINT DEFAULT 0,
    disk_write BIGINT DEFAULT 0,
    network_in BIGINT DEFAULT 0,
    network_out BIGINT DEFAULT 0,
    uptime BIGINT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_node_vmid_checked (node_name, vmid, checked_at),
    INDEX idx_status (status),
    INDEX idx_checked_at (checked_at),
    UNIQUE KEY unique_check (node_name, vmid, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Proxmox Storage monitoring
CREATE TABLE IF NOT EXISTS proxmox_storage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_name VARCHAR(100) NOT NULL,
    storage_name VARCHAR(100) NOT NULL,
    storage_type VARCHAR(50) NOT NULL,
    status ENUM('operational', 'degraded', 'down') NOT NULL,
    usage_percent DECIMAL(5,2) DEFAULT NULL,
    total_bytes BIGINT DEFAULT NULL,
    used_bytes BIGINT DEFAULT NULL,
    available_bytes BIGINT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_node_storage_checked (node_name, storage_name, checked_at),
    INDEX idx_status (status),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Minecraft server specific monitoring
CREATE TABLE IF NOT EXISTS minecraft_servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT NOT NULL DEFAULT 25565,
    status ENUM('operational', 'degraded', 'down') NOT NULL,
    players_online INT DEFAULT 0,
    max_players INT DEFAULT 0,
    server_version VARCHAR(100) DEFAULT NULL,
    motd TEXT DEFAULT NULL,
    ping_time INT DEFAULT NULL,
    protocol_version INT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name_checked (name, checked_at),
    INDEX idx_status (status),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Incidents and maintenance tracking
CREATE TABLE IF NOT EXISTS incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('investigating', 'identified', 'monitoring', 'resolved') NOT NULL DEFAULT 'investigating',
    severity ENUM('minor', 'major', 'critical') NOT NULL DEFAULT 'minor',
    affected_services JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_severity (severity),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maintenance windows
CREATE TABLE IF NOT EXISTS maintenance_windows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    affected_services JSON DEFAULT NULL,
    scheduled_start TIMESTAMP NOT NULL,
    scheduled_end TIMESTAMP NOT NULL,
    actual_start TIMESTAMP NULL DEFAULT NULL,
    actual_end TIMESTAMP NULL DEFAULT NULL,
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_scheduled_start (scheduled_start),
    INDEX idx_scheduled_end (scheduled_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification log
CREATE TABLE IF NOT EXISTS notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_type ENUM('domain', 'server', 'proxmox_node', 'proxmox_vm', 'proxmox_container', 'minecraft') NOT NULL,
    service_name VARCHAR(255) NOT NULL,
    event_type ENUM('down', 'up', 'degraded') NOT NULL,
    message TEXT NOT NULL,
    notification_sent BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service (service_type, service_name),
    INDEX idx_created_at (created_at),
    INDEX idx_notification_sent (notification_sent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cleanup old data procedure
DELIMITER //
CREATE PROCEDURE CleanupOldData()
BEGIN
    DECLARE retention_days INT DEFAULT 90;
    
    -- Clean up old domain checks
    DELETE FROM domain_checks WHERE checked_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- Clean up old server checks
    DELETE FROM server_checks WHERE checked_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- Clean up old Proxmox data
    DELETE FROM proxmox_nodes WHERE checked_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    DELETE FROM proxmox_vms WHERE checked_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    DELETE FROM proxmox_containers WHERE checked_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    DELETE FROM proxmox_storage WHERE checked_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- Clean up old Minecraft server data
    DELETE FROM minecraft_servers WHERE checked_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- Clean up old notification logs
    DELETE FROM notification_log WHERE created_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
END //
DELIMITER ;

-- Create event to run cleanup daily
CREATE EVENT IF NOT EXISTS daily_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO CALL CleanupOldData();

-- Views for easy data access
CREATE OR REPLACE VIEW current_status AS
SELECT 
    'domain' as service_type,
    name as service_name,
    status,
    checked_at
FROM domain_checks d1
WHERE d1.checked_at = (
    SELECT MAX(d2.checked_at) 
    FROM domain_checks d2 
    WHERE d2.name = d1.name
)

UNION ALL

SELECT 
    'server' as service_type,
    name as service_name,
    status,
    checked_at
FROM server_checks s1
WHERE s1.checked_at = (
    SELECT MAX(s2.checked_at) 
    FROM server_checks s2 
    WHERE s2.name = s1.name
)

UNION ALL

SELECT 
    'proxmox_node' as service_type,
    name as service_name,
    status,
    checked_at
FROM proxmox_nodes n1
WHERE n1.checked_at = (
    SELECT MAX(n2.checked_at) 
    FROM proxmox_nodes n2 
    WHERE n2.name = n1.name
)

UNION ALL

SELECT 
    'proxmox_vm' as service_type,
    CONCAT(node_name, '/', name) as service_name,
    status,
    checked_at
FROM proxmox_vms v1
WHERE v1.checked_at = (
    SELECT MAX(v2.checked_at) 
    FROM proxmox_vms v2 
    WHERE v2.node_name = v1.node_name AND v2.vmid = v1.vmid
)

UNION ALL

SELECT 
    'proxmox_container' as service_type,
    CONCAT(node_name, '/', name) as service_name,
    status,
    checked_at
FROM proxmox_containers c1
WHERE c1.checked_at = (
    SELECT MAX(c2.checked_at) 
    FROM proxmox_containers c2 
    WHERE c2.node_name = c1.node_name AND c2.vmid = c1.vmid
)

UNION ALL

SELECT 
    'minecraft' as service_type,
    name as service_name,
    status,
    checked_at
FROM minecraft_servers m1
WHERE m1.checked_at = (
    SELECT MAX(m2.checked_at) 
    FROM minecraft_servers m2 
    WHERE m2.name = m1.name
);

-- Create uptime summary view
CREATE OR REPLACE VIEW uptime_summary AS
SELECT 
    service_type,
    service_name,
    COUNT(*) as total_checks,
    COUNT(CASE WHEN status = 'operational' THEN 1 END) as operational_checks,
    ROUND(COUNT(CASE WHEN status = 'operational' THEN 1 END) * 100.0 / COUNT(*), 2) as uptime_percentage,
    MIN(checked_at) as first_check,
    MAX(checked_at) as last_check
FROM current_status
GROUP BY service_type, service_name;