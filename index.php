<?php
require_once 'config.php';
require_once 'database.php';
require_once 'functions.php';

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['api']) {
        case 'status':
            echo json_encode(getOverallStatus());
            break;
        case 'domains':
            echo json_encode(getDomainStatuses());
            break;
        case 'servers':
            echo json_encode(getServerStatuses());
            break;
        case 'history':
            $service = $_GET['service'] ?? '';
            $days = min(28, max(1, intval($_GET['days'] ?? 7)));
            echo json_encode(getServiceHistory($service, $days));
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
    exit;
}

$overallStatus = getOverallStatus();
$domainStatuses = getDomainStatuses();
$serverStatuses = getServerStatuses();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo DASHBOARD_TITLE; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #000;
            color: #fff;
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            text-align: center;
            margin-bottom: 3rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid #333;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #fff, #ccc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header p {
            color: #888;
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-operational {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .status-degraded {
            background: rgba(251, 191, 36, 0.1);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.3);
        }

        .status-down {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
        }

        .service-group {
            margin-bottom: 2rem;
            background: #111;
            border: 1px solid #333;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .service-group:hover {
            border-color: #444;
        }

        .service-header {
            padding: 1.5rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s ease;
            user-select: none;
        }

        .service-header:hover {
            background: #1a1a1a;
        }

        .service-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .service-summary {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.9rem;
            color: #888;
        }

        .expand-icon {
            transition: transform 0.3s ease;
            color: #666;
        }

        .service-group.expanded .expand-icon {
            transform: rotate(180deg);
        }

        .service-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            border-top: 1px solid #333;
        }

        .service-group.expanded .service-content {
            max-height: 2000px;
        }

        .service-items {
            padding: 0;
        }

        .service-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #222;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .service-item:last-child {
            border-bottom: none;
        }

        .service-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .service-name {
            font-weight: 500;
            color: #fff;
            font-size: 1rem;
        }

        .service-details {
            font-size: 0.85rem;
            color: #888;
        }

        .service-metrics {
            text-align: right;
            font-size: 0.85rem;
            color: #888;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.25rem;
        }

        .metric-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container {
            margin: 1rem 1.5rem;
            height: 80px;
            background: #0a0a0a;
            border-radius: 6px;
            padding: 0.5rem;
            display: none;
        }

        .service-group.expanded .chart-container {
            display: block;
        }

        .chart {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: end;
            gap: 1px;
        }

        .chart-bar {
            flex: 1;
            background: #333;
            border-radius: 1px;
            min-height: 2px;
            transition: background 0.2s ease;
        }

        .chart-bar.operational {
            background: #22c55e;
        }

        .chart-bar.degraded {
            background: #fbbf24;
        }

        .chart-bar.down {
            background: #ef4444;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: #111;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #888;
            font-size: 0.9rem;
        }

        .last-checked {
            text-align: center;
            margin-top: 2rem;
            color: #666;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .last-checked::before {
            content: '🔄';
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .service-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .service-metrics {
                text-align: left;
                align-items: flex-start;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .loading {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #333;
            border-radius: 50%;
            border-top-color: #22c55e;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo DASHBOARD_TITLE; ?></h1>
            <p><?php echo DASHBOARD_SUBTITLE; ?></p>
            <div class="status-indicator status-<?php echo $overallStatus['status']; ?>">
                <div class="status-dot"></div>
                <?php echo $overallStatus['message']; ?>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number" style="color: #22c55e;"><?php echo $overallStatus['operational']; ?></div>
                <div class="stat-label">Operational</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #fbbf24;"><?php echo $overallStatus['degraded']; ?></div>
                <div class="stat-label">Degraded</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #ef4444;"><?php echo $overallStatus['down']; ?></div>
                <div class="stat-label">Down</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #22c55e;"><?php echo $overallStatus['uptime_percentage']; ?>%</div>
                <div class="stat-label">Uptime</div>
            </div>
        </div>

        <?php if (!empty($domainStatuses)): ?>
        <div class="service-group" data-service="domains">
            <div class="service-header" onclick="toggleService('domains')">
                <div class="service-title">
                    <div class="status-dot" style="background: <?php echo getGroupStatusColor($domainStatuses); ?>"></div>
                    🌐 Website Availability
                </div>
                <div class="service-summary">
                    <span><?php echo count($domainStatuses); ?> Domains</span>
                    <svg class="expand-icon" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8 12L3 7h10L8 12z"/>
                    </svg>
                </div>
            </div>
            <div class="service-content">
                <div class="service-items">
                    <?php foreach ($domainStatuses as $domain): ?>
                    <div class="service-item">
                        <div class="service-info">
                            <div class="service-name"><?php echo htmlspecialchars($domain['name']); ?></div>
                            <div class="service-details"><?php echo htmlspecialchars($domain['url']); ?></div>
                        </div>
                        <div class="service-metrics">
                            <div class="status-indicator status-<?php echo $domain['status']; ?>" style="padding: 0.25rem 0.75rem; font-size: 0.75rem;">
                                <div class="status-dot"></div>
                                <?php echo ucfirst($domain['status']); ?>
                            </div>
                            <div class="metric-row">
                                <span><?php echo $domain['response_time']; ?>ms</span>
                                <span>•</span>
                                <span><?php echo $domain['uptime']; ?>% uptime</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="chart-container">
                    <div class="chart" id="domains-chart"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($serverStatuses)): ?>
        <div class="service-group" data-service="servers">
            <div class="service-header" onclick="toggleService('servers')">
                <div class="service-title">
                    <div class="status-dot" style="background: <?php echo getGroupStatusColor($serverStatuses); ?>"></div>
                    🖥️ Server & Services
                </div>
                <div class="service-summary">
                    <span><?php echo count($serverStatuses); ?> Services</span>
                    <svg class="expand-icon" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8 12L3 7h10L8 12z"/>
                    </svg>
                </div>
            </div>
            <div class="service-content">
                <div class="service-items">
                    <?php foreach ($serverStatuses as $server): ?>
                    <div class="service-item">
                        <div class="service-info">
                            <div class="service-name"><?php echo htmlspecialchars($server['name']); ?></div>
                            <div class="service-details">
                                <?php echo htmlspecialchars($server['host']); ?>:<?php echo $server['port']; ?>
                                <?php if ($server['type'] === 'minecraft'): ?>
                                    <span style="color: #22c55e; margin-left: 0.5rem;">⛏️ Minecraft</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="service-metrics">
                            <div class="status-indicator status-<?php echo $server['status']; ?>" style="padding: 0.25rem 0.75rem; font-size: 0.75rem;">
                                <div class="status-dot"></div>
                                <?php echo ucfirst($server['status']); ?>
                            </div>
                            <div class="metric-row">
                                <?php if ($server['type'] === 'minecraft'): ?>
                                    <span>👥 <?php echo $server['players_online']; ?>/<?php echo $server['max_players']; ?></span>
                                <?php else: ?>
                                    <span><?php echo $server['response_time']; ?>ms</span>
                                    <span>•</span>
                                    <span><?php echo $server['uptime']; ?>% uptime</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="chart-container">
                    <div class="chart" id="servers-chart"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="last-checked">
            Last Updated: <?php echo date('Y-m-d H:i:s'); ?>
            <span class="loading" id="refresh-indicator" style="display: none;"></span>
        </div>
    </div>

    <script>
        function toggleService(serviceId) {
            const serviceGroup = document.querySelector(`[data-service="${serviceId}"]`);
            serviceGroup.classList.toggle('expanded');
            
            if (serviceGroup.classList.contains('expanded')) {
                loadHistoryChart(serviceId);
            }
        }

        async function loadHistoryChart(service) {
            try {
                const response = await fetch(`?api=history&service=${service}&days=28`);
                const data = await response.json();
                renderChart(`${service}-chart`, data);
            } catch (error) {
                console.error('Error loading chart data:', error);
            }
        }

        function renderChart(containerId, data) {
            const container = document.getElementById(containerId);
            if (!container || !data.length) return;

            container.innerHTML = '';
            
            data.forEach(point => {
                const bar = document.createElement('div');
                bar.className = `chart-bar ${point.status}`;
                bar.style.height = `${Math.max(10, point.uptime)}%`;
                bar.title = `${point.date}: ${point.uptime}% uptime`;
                container.appendChild(bar);
            });
        }

        let refreshInterval;
        
        function startAutoRefresh() {
            refreshInterval = setInterval(async () => {
                const indicator = document.getElementById('refresh-indicator');
                indicator.style.display = 'inline-block';
                
                try {
                    const response = await fetch('?api=status');
                    const status = await response.json();
                    
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } catch (error) {
                    console.error('Auto-refresh failed:', error);
                } finally {
                    indicator.style.display = 'none';
                }
            }, <?php echo AUTO_REFRESH_INTERVAL * 1000; ?>);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const firstService = document.querySelector('.service-group');
            if (firstService) {
                const serviceId = firstService.getAttribute('data-service');
                toggleService(serviceId);
            }
            
            startAutoRefresh();
        });

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(refreshInterval);
            } else {
                startAutoRefresh();
            }
        });
    </script>
</body>
</html>