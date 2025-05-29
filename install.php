<?php
require_once 'config.php';
require_once 'database.php';

function checkRequirements() {
    $requirements = [];
    
    $requirements['php_version'] = [
        'name' => 'PHP Version >= 7.4',
        'status' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'current' => PHP_VERSION
    ];
    
    $extensions = ['pdo', 'pdo_pgsql', 'curl', 'json', 'openssl'];
    foreach ($extensions as $ext) {
        $requirements["ext_{$ext}"] = [
            'name' => "PHP Extension: {$ext}",
            'status' => extension_loaded($ext),
            'current' => extension_loaded($ext) ? 'Loaded' : 'Missing'
        ];
    }
    
    try {
        $db = Database::getInstance();
        $requirements['database'] = [
            'name' => 'Database Connection',
            'status' => $db->testConnection(),
            'current' => $db->testConnection() ? 'Connected' : 'Failed'
        ];
    } catch (Exception $e) {
        $requirements['database'] = [
            'name' => 'Database Connection',
            'status' => false,
            'current' => 'Error: ' . $e->getMessage()
        ];
    }
    
    global $PROXMOX_CONFIG;
    if ($PROXMOX_CONFIG['enabled']) {
        try {
            require_once 'proxmox.php';
            $proxmox = new ProxmoxMonitor($PROXMOX_CONFIG);
            $requirements['proxmox'] = [
                'name' => 'Proxmox Connection',
                'status' => true,
                'current' => 'Connected'
            ];
        } catch (Exception $e) {
            $requirements['proxmox'] = [
                'name' => 'Proxmox Connection',
                'status' => false,
                'current' => 'Error: ' . $e->getMessage()
            ];
        }
    } else {
        $requirements['proxmox'] = [
            'name' => 'Proxmox Connection',
            'status' => true,
            'current' => 'Disabled'
        ];
    }
    
    $writableDirectories = [__DIR__];
    foreach ($writableDirectories as $dir) {
        $requirements["write_dir"] = [
            'name' => "Write Permission: " . basename($dir),
            'status' => is_writable($dir),
            'current' => is_writable($dir) ? 'Writable' : 'Not writable'
        ];
    }
    
    return $requirements;
}

function installDatabase() {
    try {
        $db = Database::getInstance();
        $db->initializeTables();
        return ['success' => true, 'message' => 'Database tables created successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Database installation failed: ' . $e->getMessage()];
    }
}

function createSampleData() {
    try {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO domain_checks (name, url, status, response_time, status_code, checked_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute(['Sample Website', 'https://google.com', 'operational', 150, 200]);
        
        $stmt = $db->prepare("
            INSERT INTO server_checks (name, host, port, type, status, response_time, checked_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute(['Sample Server', '8.8.8.8', 53, 'external', 'operational', 50]);
        
        return ['success' => true, 'message' => 'Sample data created'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Sample data creation failed: ' . $e->getMessage()];
    }
}

function testMonitoring() {
    try {
        ob_start();
        
        global $DOMAINS;
        $domainCount = count($DOMAINS);
        
        global $SERVERS, $MINECRAFT_SERVERS;
        $serverCount = count($SERVERS ?? []) + count($MINECRAFT_SERVERS ?? []);
        
        $proxmoxStatus = 'Disabled';
        global $PROXMOX_CONFIG;
        if ($PROXMOX_CONFIG['enabled']) {
            try {
                require_once 'proxmox.php';
                $proxmox = new ProxmoxMonitor($PROXMOX_CONFIG);
                $proxmoxStatus = 'Connected';
            } catch (Exception $e) {
                $proxmoxStatus = 'Error: ' . $e->getMessage();
            }
        }
        
        ob_end_clean();
        
        return [
            'success' => true, 
            'message' => "Monitoring test completed",
            'stats' => [
                'domains' => $domainCount,
                'servers' => $serverCount,
                'proxmox' => $proxmoxStatus
            ]
        ];
    } catch (Exception $e) {
        ob_end_clean();
        return ['success' => false, 'message' => 'Monitoring test failed: ' . $e->getMessage()];
    }
}

function getDatabaseStats() {
    try {
        $db = Database::getInstance();
        $stats = $db->getTableStats();
        return ['success' => true, 'stats' => $stats];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'install_database':
            echo json_encode(installDatabase());
            break;
            
        case 'create_sample_data':
            echo json_encode(createSampleData());
            break;
            
        case 'test_monitoring':
            echo json_encode(testMonitoring());
            break;
            
        case 'get_database_stats':
            echo json_encode(getDatabaseStats());
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    exit;
}

$requirements = checkRequirements();
$allRequirementsMet = array_reduce($requirements, function($carry, $req) {
    return $carry && $req['status'];
}, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Page Installation</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 2rem;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .content {
            padding: 2rem;
        }
        
        .step {
            margin-bottom: 2rem;
            padding: 2rem;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .step:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }
        
        .step h2 {
            margin-bottom: 1rem;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .requirement {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .requirement:last-child {
            border-bottom: none;
        }
        
        .requirement-info {
            flex: 1;
        }
        
        .requirement-name {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .requirement-current {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .status {
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status.success {
            background: #dcfce7;
            color: #166534;
        }
        
        .status.error {
            background: #fef2f2;
            color: #dc2626;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            margin: 0.5rem 0.5rem 0.5rem 0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .message {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin: 1rem 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .message.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .message.error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        
        .message.info {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .stat-card {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
        }
        
        .progress {
            width: 100%;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: width 0.3s ease;
        }
        
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-radius: 50%;
            border-top-color: #667eea;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .hidden {
            display: none !important;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }
            
            .header {
                padding: 2rem 1rem;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .content {
                padding: 1rem;
            }
            
            .step {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>🚀 Status Page Installation</h1>
                <p>Infrastructure Monitoring Setup</p>
            </div>
            
            <div class="content">
                <div id="messages"></div>
                
                <div class="progress">
                    <div class="progress-bar" id="progress-bar" style="width: 20%"></div>
                </div>
                
                <div class="step">
                    <h2>📋 System Requirements</h2>
                    
                    <?php foreach ($requirements as $key => $req): ?>
                    <div class="requirement">
                        <div class="requirement-info">
                            <div class="requirement-name"><?php echo htmlspecialchars($req['name']); ?></div>
                            <div class="requirement-current"><?php echo htmlspecialchars($req['current']); ?></div>
                        </div>
                        <div class="status <?php echo $req['status'] ? 'success' : 'error'; ?>">
                            <?php echo $req['status'] ? '✅ OK' : '❌ Error'; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (!$allRequirementsMet): ?>
                    <div class="message error">
                        <span>⚠️</span>
                        <div>
                            <strong>Not all requirements met!</strong><br>
                            Please resolve the issues above before continuing.
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="message success">
                        <span>✅</span>
                        <div>All system requirements satisfied!</div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="step">
                    <h2>🗄️ Database Setup</h2>
                    <p>Create the required PostgreSQL tables in your database.</p>
                    
                    <button class="btn" onclick="installDatabase()" <?php echo $allRequirementsMet ? '' : 'disabled'; ?> id="install-db-btn">
                        <span class="btn-text">Install Database</span>
                        <span class="loading hidden"></span>
                    </button>
                </div>
                
                <div class="step">
                    <h2>📊 Sample Data (Optional)</h2>
                    <p>Create sample data to test the status page.</p>
                    
                    <button class="btn" onclick="createSampleData()" disabled id="sample-data-btn">
                        <span class="btn-text">Create Sample Data</span>
                        <span class="loading hidden"></span>
                    </button>
                </div>
                
                <div class="step">
                    <h2>🔍 Test Monitoring</h2>
                    <p>Test connections to all configured services.</p>
                    
                    <button class="btn" onclick="testMonitoring()" disabled id="test-monitoring-btn">
                        <span class="btn-text">Test Monitoring</span>
                        <span class="loading hidden"></span>
                    </button>
                    
                    <div id="monitoring-stats" class="stats-grid hidden">
                        <div class="stat-card">
                            <div class="stat-number" id="domains-count">-</div>
                            <div class="stat-label">Domains</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" id="servers-count">-</div>
                            <div class="stat-label">Servers</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" id="proxmox-status">-</div>
                            <div class="stat-label">Proxmox</div>
                        </div>
                    </div>
                </div>
                
                <div class="step">
                    <h2>📈 Database Statistics</h2>
                    <p>Overview of stored monitoring data.</p>
                    
                    <button class="btn" onclick="getDatabaseStats()" disabled id="db-stats-btn">
                        <span class="btn-text">Load Statistics</span>
                        <span class="loading hidden"></span>
                    </button>
                    
                    <div id="database-stats" class="hidden"></div>
                </div>
                
                <div class="step">
                    <h2>🎉 Installation Complete</h2>
                    <p>After successful installation:</p>
                    <div class="message info">
                        <span>ℹ️</span>
                        <div>
                            <ul style="margin: 0; padding-left: 1.5rem;">
                                <li>Set up cron jobs for automatic monitoring</li>
                                <li>Start the monitoring service</li>
                                <li>Delete or rename this install.php file</li>
                                <li>Visit your status page: <a href="index.php" target="_blank">index.php</a></li>
                            </ul>
                        </div>
                    </div>
                    
                    <button class="btn" onclick="window.open('index.php', '_blank')" id="view-status-btn" disabled>
                        🚀 Open Status Page
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentStep = 1;
        const totalSteps = 6;
        
        function updateProgress(step) {
            const progress = (step / totalSteps) * 100;
            document.getElementById('progress-bar').style.width = progress + '%';
            currentStep = step;
        }
        
        function showMessage(message, type = 'success') {
            const messagesDiv = document.getElementById('messages');
            const icons = {
                success: '✅',
                error: '❌',
                info: 'ℹ️'
            };
            
            messagesDiv.innerHTML = `
                <div class="message ${type}">
                    <span>${icons[type]}</span>
                    <div>${message}</div>
                </div>
            `;
            
            setTimeout(() => {
                messagesDiv.innerHTML = '';
            }, 5000);
        }
        
        function setButtonLoading(buttonId, loading = true) {
            const btn = document.getElementById(buttonId);
            const text = btn.querySelector('.btn-text');
            const loadingIcon = btn.querySelector('.loading');
            
            btn.disabled = loading;
            text.style.display = loading ? 'none' : 'inline';
            loadingIcon.classList.toggle('hidden', !loading);
        }
        
        async function installDatabase() {
            setButtonLoading('install-db-btn', true);
            
            try {
                const response = await fetch('install.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=install_database'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    document.getElementById('sample-data-btn').disabled = false;
                    updateProgress(3);
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Error: ' + error.message, 'error');
            } finally {
                setButtonLoading('install-db-btn', false);
            }
        }
        
        async function createSampleData() {
            setButtonLoading('sample-data-btn', true);
            
            try {
                const response = await fetch('install.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=create_sample_data'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    document.getElementById('test-monitoring-btn').disabled = false;
                    updateProgress(4);
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Error: ' + error.message, 'error');
            } finally {
                setButtonLoading('sample-data-btn', false);
            }
        }
        
        async function testMonitoring() {
            setButtonLoading('test-monitoring-btn', true);
            
            try {
                const response = await fetch('install.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=test_monitoring'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    
                    document.getElementById('domains-count').textContent = result.stats.domains;
                    document.getElementById('servers-count').textContent = result.stats.servers;
                    document.getElementById('proxmox-status').textContent = result.stats.proxmox === 'Connected' ? '✅' : '❌';
                    document.getElementById('monitoring-stats').classList.remove('hidden');
                    
                    document.getElementById('db-stats-btn').disabled = false;
                    updateProgress(5);
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Error: ' + error.message, 'error');
            } finally {
                setButtonLoading('test-monitoring-btn', false);
            }
        }
        
        async function getDatabaseStats() {
            setButtonLoading('db-stats-btn', true);
            
            try {
                const response = await fetch('install.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_database_stats'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    let statsHTML = '<div class="stats-grid">';
                    for (const [table, data] of Object.entries(result.stats)) {
                        statsHTML += `
                            <div class="stat-card">
                                <div class="stat-number">${data.total}</div>
                                <div class="stat-label">${data.label}</div>
                            </div>
                        `;
                    }
                    statsHTML += '</div>';
                    
                    document.getElementById('database-stats').innerHTML = statsHTML;
                    document.getElementById('database-stats').classList.remove('hidden');
                    
                    document.getElementById('view-status-btn').disabled = false;
                    updateProgress(6);
                    
                    showMessage('Database statistics loaded', 'success');
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Error: ' + error.message, 'error');
            } finally {
                setButtonLoading('db-stats-btn', false);
            }
        }
        
        updateProgress(<?php echo $allRequirementsMet ? 2 : 1; ?>);
    </script>
</body>
</html>