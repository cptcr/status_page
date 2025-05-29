<?php
require_once 'config.php';
require_once 'database.php';

function getOverallStatus() {
    $services = [
        'domains' => getDomainStatuses(),
        'servers' => getServerStatuses(),
        'proxmox' => getProxmoxStatusSummary()
    ];
    
    $totalServices = 0;
    $operationalServices = 0;
    $degradedServices = 0;
    $downServices = 0;
    
    foreach ($services as $serviceGroup) {
        if (isset($serviceGroup['error'])) continue;
        
        foreach ($serviceGroup as $service) {
            $totalServices++;
            switch ($service['status']) {
                case 'operational':
                    $operationalServices++;
                    break;
                case 'degraded':
                    $degradedServices++;
                    break;
                case 'down':
                    $downServices++;
                    break;
            }
        }
    }
    
    if ($totalServices === 0) {
        return [
            'status' => 'down', 
            'message' => 'No services configured',
            'total_services' => 0,
            'operational' => 0,
            'degraded' => 0,
            'down' => 0,
            'uptime_percentage' => 0
        ];
    }
    
    $downPercentage = ($downServices / $totalServices) * 100;
    $operationalPercentage = ($operationalServices / $totalServices) * 100;
    
    if ($downServices === 0) {
        if ($degradedServices === 0) {
            $status = 'operational';
            $message = 'All systems operational';
        } else {
            $status = 'degraded';
            $message = 'Partial service degradation';
        }
    } elseif ($downPercentage <= 20) {
        $status = 'degraded';
        $message = 'Some services unavailable';
    } else {
        $status = 'down';
        $message = 'Major outages detected';
    }
    
    return [
        'status' => $status,
        'message' => $message,
        'total_services' => $totalServices,
        'operational' => $operationalServices,
        'degraded' => $degradedServices,
        'down' => $downServices,
        'uptime_percentage' => round($operationalPercentage, 1)
    ];
}

function getDomainStatuses() {
    global $DOMAINS;
    $db = Database::getInstance()->getConnection();
    $results = [];
    
    foreach ($DOMAINS as $domain) {
        try {
            $stmt = $db->prepare("
                SELECT * FROM domain_checks 
                WHERE name = ? 
                ORDER BY checked_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$domain['name']]);
            $latest = $stmt->fetch();
            
            if (!$latest) {
                $results[] = [
                    'name' => $domain['name'],
                    'url' => $domain['url'],
                    'status' => 'down',
                    'response_time' => 0,
                    'status_code' => 0,
                    'uptime' => 0,
                    'last_checked' => null,
                    'error_message' => 'No data available'
                ];
                continue;
            }
            
            $stmt = $db->prepare("
                SELECT 
                    COUNT(CASE WHEN status = 'operational' THEN 1 END) * 100.0 / COUNT(*) as uptime
                FROM domain_checks 
                WHERE name = ? AND checked_at >= NOW() - INTERVAL '7 days'
            ");
            $stmt->execute([$domain['name']]);
            $uptime = $stmt->fetchColumn() ?? 0;
            
            $results[] = [
                'name' => $latest['name'],
                'url' => $latest['url'],
                'status' => $latest['status'],
                'response_time' => $latest['response_time'] ?? 0,
                'status_code' => $latest['status_code'] ?? 0,
                'uptime' => round($uptime, 2),
                'last_checked' => $latest['checked_at'],
                'error_message' => $latest['error_message']
            ];
            
        } catch (Exception $e) {
            error_log("Error getting domain status for {$domain['name']}: " . $e->getMessage());
            $results[] = [
                'name' => $domain['name'],
                'url' => $domain['url'],
                'status' => 'down',
                'response_time' => 0,
                'status_code' => 0,
                'uptime' => 0,
                'error_message' => 'Database error'
            ];
        }
    }
    
    return $results;
}

function getServerStatuses() {
    global $SERVERS, $MINECRAFT_SERVERS;
    $db = Database::getInstance()->getConnection();
    $results = [];
    
    if (isset($SERVERS)) {
        foreach ($SERVERS as $server) {
            try {
                $stmt = $db->prepare("
                    SELECT * FROM server_checks 
                    WHERE name = ? 
                    ORDER BY checked_at DESC 
                    LIMIT 1
                ");
                $stmt->execute([$server['name']]);
                $latest = $stmt->fetch();
                
                if (!$latest) {
                    $results[] = [
                        'name' => $server['name'],
                        'host' => $server['host'],
                        'port' => $server['port'],
                        'type' => $server['type'],
                        'status' => 'down',
                        'response_time' => 0,
                        'uptime' => 0,
                        'error_message' => 'No data available'
                    ];
                    continue;
                }
                
                $stmt = $db->prepare("
                    SELECT 
                        COUNT(CASE WHEN status = 'operational' THEN 1 END) * 100.0 / COUNT(*) as uptime
                    FROM server_checks 
                    WHERE name = ? AND checked_at >= NOW() - INTERVAL '7 days'
                ");
                $stmt->execute([$server['name']]);
                $uptime = $stmt->fetchColumn() ?? 0;
                
                $results[] = [
                    'name' => $latest['name'],
                    'host' => $latest['host'],
                    'port' => $latest['port'],
                    'type' => $latest['type'],
                    'status' => $latest['status'],
                    'response_time' => $latest['response_time'] ?? 0,
                    'uptime' => round($uptime, 2),
                    'last_checked' => $latest['checked_at'],
                    'error_message' => $latest['error_message']
                ];
                
            } catch (Exception $e) {
                error_log("Error getting server status for {$server['name']}: " . $e->getMessage());
            }
        }
    }
    
    if (isset($MINECRAFT_SERVERS)) {
        foreach ($MINECRAFT_SERVERS as $server) {
            try {
                $stmt = $db->prepare("
                    SELECT * FROM minecraft_servers 
                    WHERE name = ? 
                    ORDER BY checked_at DESC 
                    LIMIT 1
                ");
                $stmt->execute([$server['name']]);
                $latest = $stmt->fetch();
                
                if (!$latest) {
                    $results[] = [
                        'name' => $server['name'],
                        'host' => $server['host'],
                        'port' => $server['port'],
                        'type' => 'minecraft',
                        'status' => 'down',
                        'players_online' => 0,
                        'max_players' => 0,
                        'uptime' => 0,
                        'error_message' => 'No data available'
                    ];
                    continue;
                }
                
                $stmt = $db->prepare("
                    SELECT 
                        COUNT(CASE WHEN status = 'operational' THEN 1 END) * 100.0 / COUNT(*) as uptime
                    FROM minecraft_servers 
                    WHERE name = ? AND checked_at >= NOW() - INTERVAL '7 days'
                ");
                $stmt->execute([$server['name']]);
                $uptime = $stmt->fetchColumn() ?? 0;
                
                $results[] = [
                    'name' => $latest['name'],
                    'host' => $latest['host'],
                    'port' => $latest['port'],
                    'type' => 'minecraft',
                    'status' => $latest['status'],
                    'players_online' => $latest['players_online'] ?? 0,
                    'max_players' => $latest['max_players'] ?? 0,
                    'server_version' => $latest['server_version'],
                    'ping_time' => $latest['ping_time'] ?? 0,
                    'response_time' => $latest['ping_time'] ?? 0,
                    'uptime' => round($uptime, 2),
                    'last_checked' => $latest['checked_at'],
                    'error_message' => $latest['error_message']
                ];
                
            } catch (Exception $e) {
                error_log("Error getting Minecraft server status for {$server['name']}: " . $e->getMessage());
            }
        }
    }
    
    return $results;
}

function getProxmoxStatusSummary() {
    global $PROXMOX_CONFIG;
    
    if (!$PROXMOX_CONFIG['enabled']) {
        return [];
    }
    
    try {
        if (!class_exists('ProxmoxMonitor')) {
            require_once 'proxmox.php';
        }
        
        $proxmoxMonitor = new ProxmoxMonitor($PROXMOX_CONFIG);
        $status = $proxmoxMonitor->getProxmoxStatus();
        
        $results = [];
        
        if (isset($status['nodes'])) {
            foreach ($status['nodes'] as $node) {
                $results[] = [
                    'name' => "Node: {$node['name']}",
                    'host' => $node['name'],
                    'port' => 8006,
                    'type' => 'proxmox_node',
                    'status' => $node['status'],
                    'cpu_usage' => $node['cpu_usage'],
                    'memory_usage' => $node['memory_usage'],
                    'disk_usage' => $node['disk_usage'] ?? 0,
                    'uptime' => formatUptime($node['uptime'] ?? 0),
                    'last_checked' => $node['checked_at']
                ];
            }
        }
        
        if (isset($status['vms'])) {
            foreach ($status['vms'] as $vm) {
                $results[] = [
                    'name' => "VM: {$vm['name']}",
                    'host' => $vm['node_name'],
                    'port' => $vm['vmid'],
                    'type' => 'proxmox_vm',
                    'status' => $vm['status'],
                    'cpu_usage' => $vm['cpu_usage'],
                    'memory_usage' => $vm['memory_usage'],
                    'vm_status' => $vm['vm_status'],
                    'uptime' => 0,
                    'last_checked' => $vm['checked_at']
                ];
            }
        }
        
        if (isset($status['containers'])) {
            foreach ($status['containers'] as $container) {
                $results[] = [
                    'name' => "CT: {$container['name']}",
                    'host' => $container['node_name'],
                    'port' => $container['vmid'],
                    'type' => 'proxmox_container',
                    'status' => $container['status'],
                    'cpu_usage' => $container['cpu_usage'],
                    'memory_usage' => $container['memory_usage'],
                    'container_status' => $container['container_status'],
                    'uptime' => 0,
                    'last_checked' => $container['checked_at']
                ];
            }
        }
        
        return $results;
        
    } catch (Exception $e) {
        error_log("Error getting Proxmox status: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

function getServiceHistory($service, $days = 7) {
    $db = Database::getInstance()->getConnection();
    $history = [];
    
    try {
        switch ($service) {
            case 'domains':
                $stmt = $db->prepare("
                    SELECT 
                        DATE(checked_at) as date,
                        name,
                        COUNT(CASE WHEN status = 'operational' THEN 1 END) * 100.0 / COUNT(*) as uptime,
                        AVG(response_time) as avg_response_time,
                        MIN(CASE WHEN status != 'operational' THEN status END) as worst_status
                    FROM domain_checks 
                    WHERE checked_at >= NOW() - INTERVAL '{$days} days'
                    GROUP BY DATE(checked_at), name
                    ORDER BY date DESC
                    LIMIT 28
                ");
                $stmt->execute();
                $data = $stmt->fetchAll();
                
                foreach ($data as $row) {
                    $status = 'operational';
                    if ($row['uptime'] < 100) {
                        $status = $row['worst_status'] ?: 'degraded';
                    }
                    
                    $history[] = [
                        'date' => $row['date'],
                        'service' => $row['name'],
                        'status' => $status,
                        'uptime' => round($row['uptime'], 1),
                        'avg_response_time' => round($row['avg_response_time'] ?? 0)
                    ];
                }
                break;
                
            case 'servers':
                $stmt = $db->prepare("
                    SELECT 
                        DATE(checked_at) as date,
                        name,
                        COUNT(CASE WHEN status = 'operational' THEN 1 END) * 100.0 / COUNT(*) as uptime,
                        AVG(response_time) as avg_response_time,
                        MIN(CASE WHEN status != 'operational' THEN status END) as worst_status
                    FROM server_checks 
                    WHERE checked_at >= NOW() - INTERVAL '{$days} days'
                    GROUP BY DATE(checked_at), name
                    ORDER BY date DESC
                    LIMIT 28
                ");
                $stmt->execute();
                $data = $stmt->fetchAll();
                
                foreach ($data as $row) {
                    $status = 'operational';
                    if ($row['uptime'] < 100) {
                        $status = $row['worst_status'] ?: 'degraded';
                    }
                    
                    $history[] = [
                        'date' => $row['date'],
                        'service' => $row['name'],
                        'status' => $status,
                        'uptime' => round($row['uptime'], 1),
                        'avg_response_time' => round($row['avg_response_time'] ?? 0)
                    ];
                }
                
                $stmt = $db->prepare("
                    SELECT 
                        DATE(checked_at) as date,
                        name,
                        COUNT(CASE WHEN status = 'operational' THEN 1 END) * 100.0 / COUNT(*) as uptime,
                        AVG(ping_time) as avg_response_time,
                        MIN(CASE WHEN status != 'operational' THEN status END) as worst_status
                    FROM minecraft_servers 
                    WHERE checked_at >= NOW() - INTERVAL '{$days} days'
                    GROUP BY DATE(checked_at), name
                    ORDER BY date DESC
                    LIMIT 28
                ");
                $stmt->execute();
                $mcData = $stmt->fetchAll();
                
                foreach ($mcData as $row) {
                    $status = 'operational';
                    if ($row['uptime'] < 100) {
                        $status = $row['worst_status'] ?: 'degraded';
                    }
                    
                    $history[] = [
                        'date' => $row['date'],
                        'service' => $row['name'] . ' (MC)',
                        'status' => $status,
                        'uptime' => round($row['uptime'], 1),
                        'avg_response_time' => round($row['avg_response_time'] ?? 0)
                    ];
                }
                break;
        }
        
        return $history;
        
    } catch (Exception $e) {
        error_log("Error getting service history: " . $e->getMessage());
        return [];
    }
}

function getGroupStatusColor($services) {
    if (empty($services)) return '#666';
    
    $hasDown = false;
    $hasDegraded = false;
    
    foreach ($services as $service) {
        if ($service['status'] === 'down') {
            $hasDown = true;
        } elseif ($service['status'] === 'degraded') {
            $hasDegraded = true;
        }
    }
    
    if ($hasDown) return '#ef4444';
    if ($hasDegraded) return '#fbbf24';
    return '#22c55e';
}

function formatUptime($seconds) {
    if ($seconds < 60) {
        return $seconds . 's';
    } elseif ($seconds < 3600) {
        return round($seconds / 60) . 'm';
    } elseif ($seconds < 86400) {
        return round($seconds / 3600, 1) . 'h';
    } else {
        return round($seconds / 86400, 1) . 'd';
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

function getSystemHealth() {
    $overall = getOverallStatus();
    
    return [
        'status' => $overall['status'],
        'uptime_percentage' => $overall['uptime_percentage'],
        'total_services' => $overall['total_services'],
        'operational_services' => $overall['operational'],
        'issues_count' => $overall['down'] + $overall['degraded'],
        'last_updated' => date('Y-m-d H:i:s')
    ];
}
?>