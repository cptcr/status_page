# Status Page - Infrastructure Monitoring Dashboard

A comprehensive status page for monitoring domains, servers, Minecraft servers, and Proxmox infrastructure with a minimalist black/white design.

## Features

### 🔍 Monitoring
- **Domain Monitoring**: HTTP/HTTPS checks with response time tracking
- **Server Monitoring**: Port connectivity and availability checks
- **Minecraft Server**: Player count, server status, MOTD tracking
- **Proxmox Integration**: Complete cluster monitoring
  - Nodes (CPU, RAM, Disk, Uptime)
  - VMs (Status, Resources, Performance)
  - Containers/LXC (Status, Metrics)
  - Storage Pools (Disk space monitoring)

### 📊 Dashboard
- **Minimalist Design**: Clean black/white theme
- **Collapsible Sections**: BetterStack-like UI
- **28-Day History**: Performance charts and trends
- **Responsive Design**: Mobile and desktop optimized
- **Auto-Refresh**: Configurable refresh intervals

### 🚨 Alerting
- **Threshold-based**: CPU >90%, RAM >95%, Disk >95%
- **Email Notifications**: SMTP integration
- **Status-based Colors**: Green/Yellow/Red indicators

## Quick Installation

### Auto Setup
```bash
curl -fsSL https://raw.githubusercontent.com/cptcr/status_page/main/install.sh | bash
```

### Manual Installation
```bash
# Clone repository
git clone https://github.com/cptcr/status_page.git
cd status_page

# Make installer executable
chmod +x install.sh

# Run installer
./install.sh
```

## Requirements

- **PHP 7.4+** with extensions: `pdo`, `pdo_pgsql`, `curl`, `json`, `openssl`
- **Apache/Nginx** web server
- **PostgreSQL Database** (any provider: Neon.tech, AWS RDS, local, etc.)
- **Proxmox VE** (optional - for infrastructure monitoring)

## Configuration

The installer will prompt you for:

1. **Database Connection**
   - PostgreSQL host, database, username, password
   - Or connection string: `postgresql://user:pass@host:5432/db`

2. **Proxmox Settings** (optional)
   - Host, username, password, realm

3. **Monitored Services**
   - Domains/websites to monitor
   - Servers and ports to check
   - Minecraft servers

4. **Email Notifications** (optional)
   - SMTP configuration for alerts

## Manual Configuration

Edit `config.php` after installation:

```php
// Database
define('DB_HOST', 'your-postgres-host');
define('DB_NAME', 'your-database');
define('DB_USER', 'your-username');
define('DB_PASS', 'your-password');
define('DB_PORT', 5432);

// Proxmox
$PROXMOX_CONFIG = [
    'host' => 'your-proxmox-host',
    'username' => 'monitoring',
    'password' => 'your-password',
    'realm' => 'pam',
    'enabled' => true
];

// Domains to monitor
$DOMAINS = [
    [
        'name' => 'Main Website',
        'url' => 'https://example.com',
        'expected_code' => 200,
        'timeout' => 10,
        'verify_ssl' => true
    ]
];

// Servers to monitor
$SERVERS = [
    [
        'name' => 'Web Server',
        'host' => '192.168.1.100',
        'port' => 80,
        'type' => 'external',
        'timeout' => 5
    ]
];

// Minecraft servers
$MINECRAFT_SERVERS = [
    [
        'name' => 'Survival Server',
        'host' => 'mc.example.com',
        'port' => 25565,
        'timeout' => 5
    ]
];
```

## Monitoring Setup

### Automatic Monitoring (Recommended)
The installer sets up systemd timers automatically:

```bash
# Check status
systemctl status status-page-monitor.timer

# View logs
journalctl -u status-page-monitor.service -f
```

### Manual Cron Jobs
```bash
# Edit crontab
crontab -e

# Add monitoring jobs
*/2 * * * * /usr/bin/php /var/www/html/status/monitor.php domains
*/5 * * * * /usr/bin/php /var/www/html/status/monitor.php servers
*/2 * * * * /usr/bin/php /var/www/html/status/monitor.php minecraft
*/3 * * * * /usr/bin/php /var/www/html/status/monitor.php proxmox
0 2 * * * /usr/bin/php /var/www/html/status/monitor.php cleanup
```

## Proxmox Setup

### Create Monitoring User
```bash
# In Proxmox shell
pveum user add monitoring@pam --password 'your-password'
pveum aclmod / -user monitoring@pam -role PVEAuditor
```

### Or use existing user (root)
```php
$PROXMOX_CONFIG = [
    'username' => 'root',
    'password' => 'your-root-password'
];
```

## SSL/HTTPS Setup

### Automatic (with domain)
```bash
# Install certbot
apt install certbot python3-certbot-apache -y

# Get certificate
certbot --apache -d status.yourdomain.com
```

### Manual Apache VHost
```apache
<VirtualHost *:80>
    ServerName status.yourdomain.com
    DocumentRoot /var/www/html/status
    
    <Directory /var/www/html/status>
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>
</VirtualHost>
```

## API Endpoints

The status page provides REST API endpoints:

```bash
# Overall status
curl https://status.yourdomain.com/?api=status

# Domain status
curl https://status.yourdomain.com/?api=domains

# Server status
curl https://status.yourdomain.com/?api=servers

# Historical data
curl https://status.yourdomain.com/?api=history&service=domains&days=7
```

### Example API Response
```json
{
  "status": "operational",
  "message": "All systems operational",
  "uptime_percentage": 99.5,
  "total_services": 12,
  "operational": 12,
  "degraded": 0,
  "down": 0
}
```

## Troubleshooting

### Common Issues

**Database Connection Failed**
```bash
# Test connection
php -r "
\$pdo = new PDO('pgsql:host=HOST;dbname=DB', 'USER', 'PASS');
echo 'Connected successfully';
"
```

**Proxmox Connection Failed**
```bash
# Test API
curl -k -d "username=USER@pam&password=PASS" \
https://PROXMOX-HOST:8006/api2/json/access/ticket
```

**500 Internal Server Error**
```bash
# Check Apache logs
tail -f /var/log/apache2/error.log

# Check PHP syntax
php -l /var/www/html/status/index.php
```

**Services Show as Down**
```bash
# Run manual check
php /var/www/html/status/monitor.php test

# Check database tables
php /var/www/html/status/monitor.php domains
```

### Log Files
- Apache errors: `/var/log/apache2/error.log`
- Monitoring logs: `/var/log/status-page/`
- Systemd logs: `journalctl -u status-page-monitor.service`

## File Structure

```
status_page/
├── index.php              # Main dashboard
├── config.php             # Configuration
├── database.php           # Database class
├── functions.php          # Helper functions
├── proxmox.php           # Proxmox API integration
├── monitor.php           # Monitoring cron script
├── install.php           # Web-based installer
├── install.sh            # Automated installer
└── README.md            # This file
```

## Database Schema

The application automatically creates these PostgreSQL tables:
- `domain_checks` - Website monitoring data
- `server_checks` - Server connectivity data
- `proxmox_nodes` - Proxmox node metrics
- `proxmox_vms` - Virtual machine status
- `proxmox_containers` - Container status
- `minecraft_servers` - Minecraft server data
- `notification_log` - Alert history

## Performance Optimization

### Database Indexes
Tables are automatically indexed for optimal performance.

### Caching
- Static files cached for 1 month
- API responses can be cached with Redis/Memcached

### Resource Usage
- Typical memory usage: 64-128MB
- CPU usage: Minimal during checks
- Database size: ~1MB per month of data

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## License

MIT License - see LICENSE file for details.

## Support

For issues or questions:
1. Check the troubleshooting section
2. Review log files
3. Create a GitHub issue with:
   - PHP version
   - Database type/version
   - Error messages
   - Configuration (without passwords)

---