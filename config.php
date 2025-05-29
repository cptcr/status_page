<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'status_page');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_PORT', 5432);

$PROXMOX_CONFIG = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => 'your_password',
    'realm' => 'pam',
    'port' => 8006,
    'enabled' => true
];

$DOMAINS = [
    [
        'name' => 'Main Website',
        'url' => 'https://example.com',
        'expected_code' => 200,
        'timeout' => 10,
        'verify_ssl' => true
    ]
];

$SERVERS = [
    [
        'name' => 'Web Server',
        'host' => '192.168.1.100',
        'port' => 80,
        'type' => 'external',
        'timeout' => 5
    ]
];

$MINECRAFT_SERVERS = [
    [
        'name' => 'Survival Server',
        'host' => 'mc.example.com',
        'port' => 25565,
        'timeout' => 5
    ]
];

$NOTIFICATION_EMAIL = 'admin@example.com';
$SMTP_HOST = 'smtp.gmail.com';
$SMTP_PORT = 587;
$SMTP_USERNAME = 'notifications@example.com';
$SMTP_PASSWORD = 'your_password';

define('CHECK_INTERVAL_DOMAINS', 2);
define('CHECK_INTERVAL_SERVERS', 5);
define('CHECK_INTERVAL_PROXMOX', 3);
define('HISTORY_RETENTION_DAYS', 90);

define('CPU_WARNING_THRESHOLD', 80);
define('CPU_CRITICAL_THRESHOLD', 95);
define('MEMORY_WARNING_THRESHOLD', 85);
define('MEMORY_CRITICAL_THRESHOLD', 95);
define('DISK_WARNING_THRESHOLD', 85);
define('DISK_CRITICAL_THRESHOLD', 95);

define('DASHBOARD_TITLE', 'Infrastructure Status');
define('DASHBOARD_SUBTITLE', 'Server & Services Monitoring');
define('AUTO_REFRESH_INTERVAL', 30);
define('SHOW_DETAILED_METRICS', true);
define('SHOW_PERFORMANCE_CHARTS', true);
?>