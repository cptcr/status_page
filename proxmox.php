<?php
class ProxmoxAPI {
    private $host;
    private $username;
    private $password;
    private $realm;
    private $port;
    private $ticket;
    private $csrf_token;
    
    public function __construct($host, $username, $password, $realm = 'pam', $port = 8006) {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->realm = $realm;
        $this->port = $port;
        
        $this->authenticate();
    }
    
    private function authenticate() {
        $url = "https://{$this->host}:{$this->port}/api2/json/access/ticket";
        
        $data = [
            'username' => $this->username . '@' . $this->realm,
            'password' => $this->password
        ];
        
        $response = $this->makeRequest('POST', $url, $data, false);
        
        if (isset($response['data'])) {
            $this->ticket = $response['data']['ticket'];
            $this->csrf_token = $response['data']['CSRFPreventionToken'];
        } else {
            throw new Exception('Proxmox authentication failed');
        }
    }
    
    private function makeRequest($method, $url, $data = null, $auth = true) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method
        ]);
        
        if ($auth && $this->ticket) {
            curl_setopt($ch, CURLOPT_COOKIE, "PVEAuthCookie={$this->ticket}");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "CSRFPreventionToken: {$this->csrf_token}"
            ]);
        }
        
        if ($data && in_array($method, ['POST', 'PUT'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: $httpCode - Response: $response");
        }
        
        return json_decode($response, true);
    }
    
    public function getNodes() {
        $url = "https://{$this->host}:{$this->port}/api2/json/nodes";
        return $this->makeRequest('GET', $url);
    }
    
    public function getNodeStatus($node) {
        $url = "https://{$this->host}:{$this->port}/api2/json/nodes/{$node}/status";
        return $this->makeRequest('GET', $url);
    }
    
    public function getNodeVMs($node) {
        $url = "https://{$this->host}:{$this->port}/api2/json/nodes/{$node}/qemu";
        return $this->makeRequest('GET', $url);
    }
    
    public function getNodeContainers($node) {
        $url = "https://{$this->host}:{$this->port}/api2/json/nodes/{$node}/lxc";
        return $this->makeRequest('GET', $url);
    }
    
    public function getVMStatus($node, $vmid) {
        $url = "https://{$this->host}:{$this->port}/api2/json/nodes/{$node}/qemu/{$vmid}/status/current";
        return $this->makeRequest('GET', $url);
    }
    
    public function getContainerStatus($node, $vmid) {
        $url = "https://{$this->host}:{$this->port}/api2/json/nodes/{$node}/lxc/{$vmid}/status/current";
        return $this->makeRequest('GET', $url);
    }
}

class ProxmoxMonitor {
    private $api;
    private $db;
    
    public function __construct($proxmoxConfig) {
        $this->api = new ProxmoxAPI(
            $proxmoxConfig['host'],
            $proxmoxConfig['username'],
            $proxmoxConfig['password'],
            $proxmoxConfig['realm'] ?? 'pam',
            $proxmoxConfig['port'] ?? 8006
        );
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function checkAllServices() {
        try {
            $nodes = $this->api->getNodes()['data'];
            $results = [];
            
            foreach ($nodes as $node) {
                $nodeName = $node['node'];
                
                $nodeStatus = $this->checkNodeStatus($nodeName);
                $results['nodes'][$nodeName] = $nodeStatus;
                
                $vms = $this->checkVMs($nodeName);
                $results['vms'][$nodeName] = $vms;
                
                $containers = $this->checkContainers($nodeName);
                $results['containers'][$nodeName] = $containers;
            }
            
            $this->saveResults($results);
            return $results;
            
        } catch (Exception $e) {
            error_log("Proxmox monitoring error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    private function checkNodeStatus($nodeName) {
        try {
            $status = $this->api->getNodeStatus($nodeName)['data'];
            
            $cpuUsage = round(($status['cpu'] ?? 0) * 100, 2);
            $memoryUsage = round((($status['memory']['used'] ?? 0) / ($status['memory']['total'] ?? 1)) * 100, 2);
            $diskUsage = round((($status['rootfs']['used'] ?? 0) / ($status['rootfs']['total'] ?? 1)) * 100, 2);
            $uptime = $status['uptime'] ?? 0;
            $loadavg = $status['loadavg'] ?? [];
            
            $nodeStatus = 'operational';
            if ($cpuUsage > 95 || $memoryUsage > 95 || $diskUsage > 95) {
                $nodeStatus = 'down';
            } elseif ($cpuUsage > 85 || $memoryUsage > 85 || $diskUsage > 85) {
                $nodeStatus = 'degraded';
            }
            
            return [
                'name' => $nodeName,
                'status' => $nodeStatus,
                'cpu_usage' => $cpuUsage,
                'memory_usage' => $memoryUsage,
                'disk_usage' => $diskUsage,
                'uptime' => $uptime,
                'load_average' => $loadavg
            ];
            
        } catch (Exception $e) {
            return [
                'name' => $nodeName,
                'status' => 'down',
                'error' => $e->getMessage(),
                'cpu_usage' => 0,
                'memory_usage' => 0,
                'disk_usage' => 0,
                'uptime' => 0
            ];
        }
    }
    
    private function checkVMs($nodeName) {
        try {
            $vms = $this->api->getNodeVMs($nodeName)['data'];
            $results = [];
            
            foreach ($vms as $vm) {
                $vmid = $vm['vmid'];
                
                try {
                    $vmStatus = $this->api->getVMStatus($nodeName, $vmid)['data'];
                    
                    $status = 'down';
                    if ($vmStatus['status'] === 'running') {
                        $status = 'operational';
                    } elseif ($vmStatus['status'] === 'paused') {
                        $status = 'degraded';
                    }
                    
                    $results[] = [
                        'vmid' => $vmid,
                        'name' => $vm['name'] ?? "VM-{$vmid}",
                        'status' => $status,
                        'vm_status' => $vmStatus['status'],
                        'cpu_usage' => isset($vmStatus['cpu']) ? round($vmStatus['cpu'] * 100, 2) : 0,
                        'memory_usage' => isset($vmStatus['mem'], $vmStatus['maxmem']) ? 
                            round(($vmStatus['mem'] / $vmStatus['maxmem']) * 100, 2) : 0
                    ];
                } catch (Exception $e) {
                    $results[] = [
                        'vmid' => $vmid,
                        'name' => $vm['name'] ?? "VM-{$vmid}",
                        'status' => 'down',
                        'vm_status' => 'unknown',
                        'cpu_usage' => 0,
                        'memory_usage' => 0,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return $results;
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    private function checkContainers($nodeName) {
        try {
            $containers = $this->api->getNodeContainers($nodeName)['data'];
            $results = [];
            
            foreach ($containers as $container) {
                $vmid = $container['vmid'];
                
                try {
                    $containerStatus = $this->api->getContainerStatus($nodeName, $vmid)['data'];
                    
                    $status = 'down';
                    if ($containerStatus['status'] === 'running') {
                        $status = 'operational';
                    } elseif ($containerStatus['status'] === 'paused') {
                        $status = 'degraded';
                    }
                    
                    $results[] = [
                        'vmid' => $vmid,
                        'name' => $container['name'] ?? "CT-{$vmid}",
                        'status' => $status,
                        'container_status' => $containerStatus['status'],
                        'cpu_usage' => isset($containerStatus['cpu']) ? round($containerStatus['cpu'] * 100, 2) : 0,
                        'memory_usage' => isset($containerStatus['mem'], $containerStatus['maxmem']) ? 
                            round(($containerStatus['mem'] / $containerStatus['maxmem']) * 100, 2) : 0
                    ];
                } catch (Exception $e) {
                    $results[] = [
                        'vmid' => $vmid,
                        'name' => $container['name'] ?? "CT-{$vmid}",
                        'status' => 'down',
                        'container_status' => 'unknown',
                        'cpu_usage' => 0,
                        'memory_usage' => 0,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return $results;
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    private function saveResults($results) {
        try {
            if (isset($results['nodes'])) {
                foreach ($results['nodes'] as $node) {
                    if (!isset($node['error'])) {
                        $stmt = $this->db->prepare("
                            INSERT INTO proxmox_nodes (name, status, cpu_usage, memory_usage, disk_usage, uptime, load_average, checked_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $node['name'],
                            $node['status'],
                            $node['cpu_usage'],
                            $node['memory_usage'],
                            $node['disk_usage'],
                            $node['uptime'],
                            json_encode($node['load_average'])
                        ]);
                    }
                }
            }
            
            if (isset($results['vms'])) {
                foreach ($results['vms'] as $nodeName => $vms) {
                    if (!isset($vms['error'])) {
                        foreach ($vms as $vm) {
                            if (!isset($vm['error'])) {
                                $stmt = $this->db->prepare("
                                    INSERT INTO proxmox_vms (node_name, vmid, name, status, vm_status, cpu_usage, memory_usage, checked_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $nodeName,
                                    $vm['vmid'],
                                    $vm['name'],
                                    $vm['status'],
                                    $vm['vm_status'],
                                    $vm['cpu_usage'],
                                    $vm['memory_usage']
                                ]);
                            }
                        }
                    }
                }
            }
            
            if (isset($results['containers'])) {
                foreach ($results['containers'] as $nodeName => $containers) {
                    if (!isset($containers['error'])) {
                        foreach ($containers as $container) {
                            if (!isset($container['error'])) {
                                $stmt = $this->db->prepare("
                                    INSERT INTO proxmox_containers (node_name, vmid, name, status, container_status, cpu_usage, memory_usage, checked_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $nodeName,
                                    $container['vmid'],
                                    $container['name'],
                                    $container['status'],
                                    $container['container_status'],
                                    $container['cpu_usage'],
                                    $container['memory_usage']
                                ]);
                            }
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("Error saving Proxmox results: " . $e->getMessage());
        }
    }
    
    public function getProxmoxStatus() {
        try {
            $stmt = $this->db->prepare("
                SELECT n1.* FROM proxmox_nodes n1
                INNER JOIN (
                    SELECT name, MAX(checked_at) as max_checked
                    FROM proxmox_nodes
                    WHERE checked_at > NOW() - INTERVAL '10 minutes'
                    GROUP BY name
                ) n2 ON n1.name = n2.name AND n1.checked_at = n2.max_checked
            ");
            $stmt->execute();
            $nodes = $stmt->fetchAll();
            
            $stmt = $this->db->prepare("
                SELECT v1.* FROM proxmox_vms v1
                INNER JOIN (
                    SELECT node_name, vmid, MAX(checked_at) as max_checked
                    FROM proxmox_vms
                    WHERE checked_at > NOW() - INTERVAL '10 minutes'
                    GROUP BY node_name, vmid
                ) v2 ON v1.node_name = v2.node_name AND v1.vmid = v2.vmid AND v1.checked_at = v2.max_checked
            ");
            $stmt->execute();
            $vms = $stmt->fetchAll();
            
            $stmt = $this->db->prepare("
                SELECT c1.* FROM proxmox_containers c1
                INNER JOIN (
                    SELECT node_name, vmid, MAX(checked_at) as max_checked
                    FROM proxmox_containers
                    WHERE checked_at > NOW() - INTERVAL '10 minutes'
                    GROUP BY node_name, vmid
                ) c2 ON c1.node_name = c2.node_name AND c1.vmid = c2.vmid AND c1.checked_at = c2.max_checked
            ");
            $stmt->execute();
            $containers = $stmt->fetchAll();
            
            return [
                'nodes' => $nodes,
                'vms' => $vms,
                'containers' => $containers
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
?>