#!/usr/bin/env php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/proxmox.php';

class StatusMonitor {
    private $db;
    private $proxmoxMonitor;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        
        global $PROXMOX_CONFIG;
        if ($PROXMOX_CONFIG['enabled']) {
            try {
                $this->proxmoxMonitor = new ProxmoxMonitor($PROXMOX_CONFIG);
            } catch (Exception $e) {
                error_log("Failed to initialize Proxmox monitor: " . $e->getMessage());
            }
        }
    }
    
    public function checkDomains() {
        global $DOMAINS;
        echo "Checking domains...\n";
        
        foreach ($DOMAINS as $domain) {
            try {
                $result = $this->checkDomain($domain);
                $this->saveDomainCheck($result);
                
                echo "✓ {$domain['name']}: {$result['status']} ({$result['response_time']}ms)\n";
            } catch (Exception $e) {
                echo "✗ {$domain['name']}: Error - {$e->getMessage()}\n";
                error_log("Domain check error for {$domain['name']}: " . $e->getMessage());
            }
        }
    }
    
    public function checkServers() {
        global $SERVERS;
        echo "Checking servers...\n";
        
        if (isset($SERVERS)) {
            foreach ($SERVERS as $server) {
                try {
                    $result = $this->checkServer($server);
                    $this->saveServerCheck($result);
                    
                    echo "✓ {$server['name']}: {$result['status']}\n";
                } catch (Exception $e) {
                    echo "✗ {$server['name']}: Error - {$e->getMessage()}\n";
                    error_log("Server check error for {$server['name']}: " . $e->getMessage());
                }
            }
        }
    }
    
    public function checkMinecraftServers() {
        global $MINECRAFT_SERVERS;
        echo "Checking Minecraft servers...\n";
        
        if (isset($MINECRAFT_SERVERS)) {
            foreach ($MINECRAFT_SERVERS as $server) {
                try {
                    $result = $this->checkMinecraftServer($server);
                    $this->saveMinecraftCheck($result);
                    
                    $playerInfo = $result['players_online'] . '/' . $result['max_players'];
                    echo "✓ {$server['name']}: {$result['status']} ({$playerInfo} players)\n";
                } catch (Exception $e) {
                    echo "✗ {$server['name']}: Error - {$e->getMessage()}\n";
                    error_log("Minecraft server check error for {$server['name']}: " . $e->getMessage());
                }
            }
        }
    }
    
    public function checkProxmox() {
        if (!$this->proxmoxMonitor) {
            echo "Proxmox monitoring disabled or failed to initialize\n";
            return;
        }
        
        echo "Checking Proxmox infrastructure...\n";
        
        try {
            $results = $this->proxmoxMonitor->checkAllServices();
            
            if (isset($results['error'])) {
                echo "✗ Proxmox check failed: {$results['error']}\n";
                return;
            }
            
            $nodeCount = count($results['nodes'] ?? []);
            $vmCount = 0;
            $containerCount = 0;
            
            if (isset($results['vms'])) {
                foreach ($results['vms'] as $vms) {
                    if (!isset($vms['error'])) {
                        $vmCount += count($vms);
                    }
                }
            }
            
            if (isset($results['containers'])) {
                foreach ($results['containers'] as $containers) {
                    if (!isset($containers['error'])) {
                        $containerCount += count($containers);
                    }
                }
            }
            
            echo "✓ Proxmox check completed: {$nodeCount} nodes, {$vmCount} VMs, {$containerCount} containers\n";
            
            $this->checkProxmoxAlerts($results);
            
        } catch (Exception $e) {
            echo "✗ Proxmox check error: {$e->getMessage()}\n";
            error_log("Proxmox check error: " . $e->getMessage());
        }
    }
    
    private function checkDomain($domain) {
        $startTime = microtime(true);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $domain['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $domain['timeout'],
            CURLOPT_CONNECTTIMEOUT => $domain['timeout'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => $domain['verify_ssl'] ?? true,
            CURLOPT_SSL_VERIFYHOST => $domain['verify_ssl'] ?? true ? 2 : 0,
            CURLOPT_USERAGENT => 'StatusPage Monitor/1.0',
            CURLOPT_NOBODY => false,
            CURLOPT_HEADER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $responseTime = round((microtime(true) - $startTime) * 1000);
        
        $status = 'down';
        $errorMessage = null;
        
        if ($error) {
            $errorMessage = $error;
        } elseif ($httpCode == $domain['expected_code']) {
            $status = 'operational';
        } elseif ($httpCode >= 200 && $httpCode < 400) {
            $status = 'degraded';
            $errorMessage = "Unexpected status code: {$httpCode}";
        } else {
            $errorMessage = "HTTP {$httpCode}";
        }
        
        return [
            'name' => $domain['name'],
            'url' => $domain['url'],
            'status' => $status,
            'response_time' => $responseTime,
            'status_code' => $httpCode,
            'error_message' => $errorMessage
        ];
    }
    
    private function checkServer($server) {
        $startTime = microtime(true);
        
        $connection = @fsockopen($server['host'], $server['port'], $errno, $errstr, $server['timeout'] ?? 10);
        $responseTime = round((microtime(true) - $startTime) * 1000);
        
        if ($connection) {
            fclose($connection);
            $status = 'operational';
            $errorMessage = null;
        } else {
            $status = 'down';
            $errorMessage = "Connection failed: {$errstr} ({$errno})";
        }
        
        return [
            'name' => $server['name'],
            'host' => $server['host'],
            'port' => $server['port'],
            'type' => $server['type'],
            'status' => $status,
            'response_time' => $responseTime,
            'error_message' => $errorMessage
        ];
    }
    
    private function checkMinecraftServer($server) {
        $socket = @fsockopen($server['host'], $server['port'], $errno, $errstr, $server['timeout'] ?? 5);
        
        if (!$socket) {
            return [
                'name' => $server['name'],
                'host' => $server['host'],
                'port' => $server['port'],
                'status' => 'down',
                'players_online' => 0,
                'max_players' => 0,
                'ping_time' => 0,
                'error_message' => "Connection failed: {$errstr}"
            ];
        }
        
        $startTime = microtime(true);
        
        try {
            $packet = "\xFE\x01";
            fwrite($socket, $packet);
            
            $response = fread($socket, 512);
            fclose($socket);
            
            $pingTime = round((microtime(true) - $startTime) * 1000);
            
            if (empty($response)) {
                return [
                    'name' => $server['name'],
                    'host' => $server['host'],
                    'port' => $server['port'],
                    'status' => 'down',
                    'players_online' => 0,
                    'max_players' => 0,
                    'ping_time' => $pingTime,
                    'error_message' => 'No response from server'
                ];
            }
            
            $data = explode("\x00", $response);
            
            $playersOnline = 0;
            $maxPlayers = 0;
            $version = '';
            $motd = '';
            
            if (count($data) >= 6) {
                $motd = $data[3] ?? '';
                $playersOnline = intval($data[4] ?? 0);
                $maxPlayers = intval($data[5] ?? 0);
                $version = $data[2] ?? '';
            }
            
            $status = 'operational';
            if ($playersOnline < 0 || $maxPlayers <= 0) {
                $status = 'degraded';
            }
            
            return [
                'name' => $server['name'],
                'host' => $server['host'],
                'port' => $server['port'],
                'status' => $status,
                'players_online' => max(0, $playersOnline),
                'max_players' => max(0, $maxPlayers),
                'server_version' => $version ?? '',
                'motd' => $motd ?? '',
                'ping_time' => $pingTime
            ];
            
        } catch (Exception $e) {
            fclose($socket);
            return [
                'name' => $server['name'],
                'host' => $server['host'],
                'port' => $server['port'],
                'status' => 'down',
                'players_online' => 0,
                'max_players' => 0,
                'ping_time' => 0,
                'error_message' => $e->getMessage()
            ];
        }
    }
    
    private function saveDomainCheck($result) {
        $stmt = $this->db->prepare("
            INSERT INTO domain_checks (name, url, status, response_time, status_code, error_message, checked_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $result['name'],
            $result['url'],
            $result['status'],
            $result['response_time'],
            $result['status_code'],
            $result['error_message']
        ]);
    }
    
    private function saveServerCheck($result) {
        $stmt = $this->db->prepare("
            INSERT INTO server_checks (name, host, port, type, status, response_time, error_message, checked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $result['name'],
            $result['host'],
            $result['port'],
            $result['type'],
            $result['status'],
            $result['response_time'],
            $result['error_message']
        ]);
    }
    
    private function saveMinecraftCheck($result) {
        $stmt = $this->db->prepare("
            INSERT INTO minecraft_servers (name, host, port, status, players_online, max_players, server_version, motd, ping_time, error_message, checked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $result['name'],
            $result['host'],
            $result['port'],
            $result['status'],
            $result['players_online'],
            $result['max_players'],
            $result['server_version'],
            $result['motd'],
            $result['ping_time'],
            $result['error_message']
        ]);
    }
    
    private function checkProxmoxAlerts($results) {
        if (isset($results['nodes'])) {
            foreach ($results['nodes'] as $node) {
                if (isset($node['cpu_usage']) && $node['cpu_usage'] > CPU_CRITICAL_THRESHOLD) {
                    $this->sendAlert('proxmox_node', $node['name'], 'critical', "CPU usage critical: {$node['cpu_usage']}%");
                }
                
                if (isset($node['memory_usage']) && $node['memory_usage'] > MEMORY_CRITICAL_THRESHOLD) {
                    $this->sendAlert('proxmox_node', $node['name'], 'critical', "Memory usage critical: {$node['memory_usage']}%");
                }
                
                if (isset($node['disk_usage']) && $node['disk_usage'] > DISK_CRITICAL_THRESHOLD) {
                    $this->sendAlert('proxmox_node', $node['name'], 'critical', "Disk usage critical: {$node['disk_usage']}%");
                }
            }
        }
    }
    
    private function sendAlert($serviceType, $serviceName, $severity, $message) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM notification_log 
            WHERE service_type = ? AND service_name = ? AND message = ? 
            AND created_at > NOW() - INTERVAL '1 hour'
        ");
        $stmt->execute([$serviceType, $serviceName, $message]);
        
        if ($stmt->fetchColumn() > 0) {
            return;
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO notification_log (service_type, service_name, event_type, message, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$serviceType, $serviceName, $severity, $message]);
        
        echo "🚨 ALERT: [{$severity}] {$serviceType}/{$serviceName}: {$message}\n";
    }
    
    public function cleanupOldData() {
        echo "Cleaning up old data...\n";
        
        try {
            $db = Database::getInstance();
            $db->cleanupOldData(HISTORY_RETENTION_DAYS);
            echo "✅ Cleanup completed\n";
        } catch (Exception $e) {
            echo "✗ Cleanup failed: {$e->getMessage()}\n";
            error_log("Cleanup error: " . $e->getMessage());
        }
    }
    
    public function testConnections() {
        echo "Testing all connections...\n";
        echo str_repeat('-', 40) . "\n";
        
        try {
            $db = Database::getInstance();
            if ($db->testConnection()) {
                echo "✅ Database: Connected\n";
            } else {
                echo "❌ Database: Connection failed\n";
            }
        } catch (Exception $e) {
            echo "❌ Database: " . $e->getMessage() . "\n";
        }
        
        try {
            if ($this->proxmoxMonitor) {
                $nodes = $this->proxmoxMonitor->checkAllServices();
                if (isset($nodes['error'])) {
                    echo "❌ Proxmox: " . $nodes['error'] . "\n";
                } else {
                    echo "✅ Proxmox: Connected\n";
                }
            } else {
                echo "⚠️  Proxmox: Disabled\n";
            }
        } catch (Exception $e) {
            echo "❌ Proxmox: " . $e->getMessage() . "\n";
        }
        
        echo str_repeat('-', 40) . "\n";
    }
}

if (php_sapi_name() === 'cli') {
    $monitor = new StatusMonitor();
    $action = $argv[1] ?? 'all';
    
    echo "Starting monitoring: " . date('Y-m-d H:i:s') . "\n";
    echo str_repeat('=', 50) . "\n";
    
    switch ($action) {
        case 'domains':
            $monitor->checkDomains();
            break;
            
        case 'servers':
            $monitor->checkServers();
            break;
            
        case 'minecraft':
            $monitor->checkMinecraftServers();
            break;
            
        case 'proxmox':
            $monitor->checkProxmox();
            break;
            
        case 'cleanup':
            $monitor->cleanupOldData();
            break;
            
        case 'test':
            $monitor->testConnections();
            break;
            
        case 'all':
        default:
            $monitor->checkDomains();
            $monitor->checkServers();
            $monitor->checkMinecraftServers();
            $monitor->checkProxmox();
            break;
    }
    
    echo str_repeat('=', 50) . "\n";
    echo "Monitoring completed: " . date('Y-m-d H:i:s') . "\n";
} else {
    echo "This script must be run from command line\n";
}
?>