<?php
require_once 'config.php';

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";sslmode=prefer";
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    public function testConnection() {
        try {
            $stmt = $this->pdo->query("SELECT 1");
            return $stmt !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function initializeTables() {
        $tables = [
            "CREATE TABLE IF NOT EXISTS domain_checks (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                url VARCHAR(255) NOT NULL,
                status VARCHAR(20) CHECK (status IN ('operational', 'degraded', 'down')) NOT NULL,
                response_time INTEGER DEFAULT NULL,
                status_code INTEGER DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE INDEX IF NOT EXISTS idx_domain_name_checked ON domain_checks(name, checked_at)",
            "CREATE INDEX IF NOT EXISTS idx_domain_checked_at ON domain_checks(checked_at)",

            "CREATE TABLE IF NOT EXISTS server_checks (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                host VARCHAR(255) NOT NULL,
                port INTEGER NOT NULL,
                type VARCHAR(20) CHECK (type IN ('minecraft', 'external', 'system')) NOT NULL,
                status VARCHAR(20) CHECK (status IN ('operational', 'degraded', 'down')) NOT NULL,
                response_time INTEGER DEFAULT NULL,
                players_online INTEGER DEFAULT NULL,
                max_players INTEGER DEFAULT NULL,
                server_version VARCHAR(100) DEFAULT NULL,
                cpu_usage DECIMAL(5,2) DEFAULT NULL,
                memory_usage DECIMAL(5,2) DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE INDEX IF NOT EXISTS idx_server_name_checked ON server_checks(name, checked_at)",
            "CREATE INDEX IF NOT EXISTS idx_server_type_status ON server_checks(type, status)",
            "CREATE INDEX IF NOT EXISTS idx_server_checked_at ON server_checks(checked_at)",

            "CREATE TABLE IF NOT EXISTS proxmox_nodes (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                status VARCHAR(20) CHECK (status IN ('operational', 'degraded', 'down')) NOT NULL,
                cpu_usage DECIMAL(5,2) DEFAULT NULL,
                memory_usage DECIMAL(5,2) DEFAULT NULL,
                disk_usage DECIMAL(5,2) DEFAULT NULL,
                uptime BIGINT DEFAULT NULL,
                load_average JSONB DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE INDEX IF NOT EXISTS idx_node_name_checked ON proxmox_nodes(name, checked_at)",
            "CREATE INDEX IF NOT EXISTS idx_node_status ON proxmox_nodes(status)",
            "CREATE INDEX IF NOT EXISTS idx_node_checked_at ON proxmox_nodes(checked_at)",

            "CREATE TABLE IF NOT EXISTS proxmox_vms (
                id SERIAL PRIMARY KEY,
                node_name VARCHAR(100) NOT NULL,
                vmid INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(20) CHECK (status IN ('operational', 'degraded', 'down')) NOT NULL,
                vm_status VARCHAR(50) DEFAULT NULL,
                cpu_usage DECIMAL(5,2) DEFAULT NULL,
                memory_usage DECIMAL(5,2) DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE INDEX IF NOT EXISTS idx_vm_node_vmid_checked ON proxmox_vms(node_name, vmid, checked_at)",
            "CREATE INDEX IF NOT EXISTS idx_vm_status ON proxmox_vms(status)",
            "CREATE INDEX IF NOT EXISTS idx_vm_checked_at ON proxmox_vms(checked_at)",

            "CREATE TABLE IF NOT EXISTS proxmox_containers (
                id SERIAL PRIMARY KEY,
                node_name VARCHAR(100) NOT NULL,
                vmid INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(20) CHECK (status IN ('operational', 'degraded', 'down')) NOT NULL,
                container_status VARCHAR(50) DEFAULT NULL,
                cpu_usage DECIMAL(5,2) DEFAULT NULL,
                memory_usage DECIMAL(5,2) DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE INDEX IF NOT EXISTS idx_container_node_vmid_checked ON proxmox_containers(node_name, vmid, checked_at)",
            "CREATE INDEX IF NOT EXISTS idx_container_status ON proxmox_containers(status)",
            "CREATE INDEX IF NOT EXISTS idx_container_checked_at ON proxmox_containers(checked_at)",

            "CREATE TABLE IF NOT EXISTS minecraft_servers (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                host VARCHAR(255) NOT NULL,
                port INTEGER NOT NULL DEFAULT 25565,
                status VARCHAR(20) CHECK (status IN ('operational', 'degraded', 'down')) NOT NULL,
                players_online INTEGER DEFAULT 0,
                max_players INTEGER DEFAULT 0,
                server_version VARCHAR(100) DEFAULT NULL,
                motd TEXT DEFAULT NULL,
                ping_time INTEGER DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE INDEX IF NOT EXISTS idx_mc_name_checked ON minecraft_servers(name, checked_at)",
            "CREATE INDEX IF NOT EXISTS idx_mc_status ON minecraft_servers(status)",
            "CREATE INDEX IF NOT EXISTS idx_mc_checked_at ON minecraft_servers(checked_at)",

            "CREATE TABLE IF NOT EXISTS notification_log (
                id SERIAL PRIMARY KEY,
                service_type VARCHAR(50) NOT NULL,
                service_name VARCHAR(255) NOT NULL,
                event_type VARCHAR(20) CHECK (event_type IN ('down', 'up', 'degraded')) NOT NULL,
                message TEXT NOT NULL,
                notification_sent BOOLEAN DEFAULT FALSE,
                sent_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            
            "CREATE INDEX IF NOT EXISTS idx_notification_service ON notification_log(service_type, service_name)",
            "CREATE INDEX IF NOT EXISTS idx_notification_created_at ON notification_log(created_at)",
            "CREATE INDEX IF NOT EXISTS idx_notification_sent ON notification_log(notification_sent)"
        ];

        foreach ($tables as $query) {
            try {
                $this->pdo->exec($query);
            } catch (PDOException $e) {
                error_log("Failed to create table: " . $e->getMessage());
                throw $e;
            }
        }
    }

    public function cleanupOldData($days = 90) {
        $tables = [
            'domain_checks',
            'server_checks', 
            'proxmox_nodes',
            'proxmox_vms',
            'proxmox_containers',
            'minecraft_servers',
            'notification_log'
        ];

        foreach ($tables as $table) {
            try {
                $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE checked_at < NOW() - INTERVAL '{$days} days'");
                $stmt->execute();
            } catch (PDOException $e) {
                error_log("Cleanup failed for {$table}: " . $e->getMessage());
            }
        }
    }

    public function getTableStats() {
        $tables = [
            'domain_checks' => 'Domain Checks',
            'server_checks' => 'Server Checks',
            'proxmox_nodes' => 'Proxmox Nodes',
            'proxmox_vms' => 'Proxmox VMs',
            'proxmox_containers' => 'Proxmox Containers',
            'minecraft_servers' => 'Minecraft Servers'
        ];

        $stats = [];
        foreach ($tables as $table => $label) {
            try {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table}");
                $stmt->execute();
                $count = $stmt->fetchColumn();
                
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE checked_at > NOW() - INTERVAL '24 hours'");
                $stmt->execute();
                $recent = $stmt->fetchColumn();

                $stats[$table] = [
                    'label' => $label,
                    'total' => $count,
                    'recent' => $recent
                ];
            } catch (PDOException $e) {
                $stats[$table] = [
                    'label' => $label,
                    'total' => 0,
                    'recent' => 0,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $stats;
    }
}
?>