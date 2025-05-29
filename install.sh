#!/bin/bash

set -e

REPO_URL="https://github.com/cptcr/status_page"
INSTALL_DIR="/var/www/html/status"
LOG_FILE="/tmp/status_page_install.log"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

success() {
    echo -e "${GREEN}✅ $1${NC}" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}❌ $1${NC}" | tee -a "$LOG_FILE"
}

warning() {
    echo -e "${YELLOW}⚠️  $1${NC}" | tee -a "$LOG_FILE"
}

ask() {
    local prompt="$1"
    local default="$2"
    local var_name="$3"
    
    if [ -n "$default" ]; then
        read -p "$(echo -e "${BLUE}$prompt${NC} [${GREEN}$default${NC}]: ")" input
        eval "$var_name=\"\${input:-$default}\""
    else
        read -p "$(echo -e "${BLUE}$prompt${NC}: ")" input
        eval "$var_name=\"$input\""
    fi
}

ask_yn() {
    local prompt="$1"
    local default="$2"
    local var_name="$3"
    
    while true; do
        if [ "$default" = "y" ]; then
            read -p "$(echo -e "${BLUE}$prompt${NC} [${GREEN}Y${NC}/n]: ")" yn
            yn=${yn:-y}
        else
            read -p "$(echo -e "${BLUE}$prompt${NC} [y/${GREEN}N${NC}]: ")" yn
            yn=${yn:-n}
        fi
        
        case $yn in
            [Yy]* ) eval "$var_name=true"; break;;
            [Nn]* ) eval "$var_name=false"; break;;
            * ) echo "Please enter y or n.";;
        esac
    done
}

check_root() {
    if [[ $EUID -eq 0 ]]; then
        SUDO=""
    else
        SUDO="sudo"
        log "Running with sudo privileges"
    fi
}

install_dependencies() {
    log "Installing system dependencies..."
    
    if command -v apt-get &> /dev/null; then
        $SUDO apt-get update
        $SUDO apt-get install -y apache2 php php-pgsql php-curl php-json php-mbstring php-xml postgresql-client curl wget unzip git
    elif command -v yum &> /dev/null; then
        $SUDO yum install -y httpd php php-pgsql php-curl php-json php-mbstring php-xml postgresql curl wget unzip git
    else
        error "Unsupported operating system. Please install dependencies manually."
        exit 1
    fi
    
    success "Dependencies installed"
}

configure_apache() {
    log "Configuring Apache..."
    
    $SUDO a2enmod rewrite headers ssl expires
    
    $SUDO mkdir -p "$INSTALL_DIR"
    $SUDO chown -R www-data:www-data "$INSTALL_DIR"
    $SUDO chmod -R 755 "$INSTALL_DIR"
    
    cat > /tmp/status.conf << EOF
<VirtualHost *:80>
    DocumentRoot $INSTALL_DIR
    
    <Directory $INSTALL_DIR>
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>
    
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
    
    ErrorLog \${APACHE_LOG_DIR}/status_error.log
    CustomLog \${APACHE_LOG_DIR}/status_access.log combined
</VirtualHost>
EOF
    
    $SUDO mv /tmp/status.conf /etc/apache2/sites-available/status.conf
    $SUDO a2ensite status.conf
    $SUDO systemctl restart apache2
    
    success "Apache configured"
}

setup_monitoring() {
    log "Setting up monitoring service..."
    
    cat > /tmp/status-page-monitor.service << EOF
[Unit]
Description=Status Page Monitor
After=network.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=$INSTALL_DIR
ExecStart=/usr/bin/php $INSTALL_DIR/monitor.php all
StandardOutput=journal
StandardError=journal
EOF
    
    cat > /tmp/status-page-monitor.timer << EOF
[Unit]
Description=Run Status Page Monitor every 3 minutes
Requires=status-page-monitor.service

[Timer]
OnCalendar=*:0/3
Persistent=true

[Install]
WantedBy=timers.target
EOF
    
    $SUDO mv /tmp/status-page-monitor.service /etc/systemd/system/
    $SUDO mv /tmp/status-page-monitor.timer /etc/systemd/system/
    
    $SUDO systemctl daemon-reload
    $SUDO systemctl enable status-page-monitor.timer
    
    success "Monitoring service configured"
}

download_files() {
    log "Downloading status page files from GitHub..."
    
    if [ -d "$INSTALL_DIR" ]; then
        $SUDO rm -rf "$INSTALL_DIR"
    fi
    
    $SUDO mkdir -p "$INSTALL_DIR"
    cd /tmp
    
    if [ -d "status_page" ]; then
        rm -rf status_page
    fi
    
    git clone "$REPO_URL.git" status_page
    cd status_page
    
    $SUDO cp *.php "$INSTALL_DIR/"
    $SUDO cp .htaccess "$INSTALL_DIR/" 2>/dev/null || true
    
    $SUDO chown -R www-data:www-data "$INSTALL_DIR"
    $SUDO chmod -R 755 "$INSTALL_DIR"
    
    success "Files downloaded and installed"
}

configure_database() {
    log "Database Configuration"
    echo "========================"
    
    ask_yn "Do you have a PostgreSQL connection string?" "n" "HAS_CONNECTION_STRING"
    
    if [ "$HAS_CONNECTION_STRING" = true ]; then
        echo "Enter your connection string:"
        echo "Format: postgresql://user:pass@host:port/database"
        read -p "Connection String: " CONNECTION_STRING
        
        if [[ $CONNECTION_STRING =~ postgresql://([^:]+):([^@]+)@([^:/]+):?([0-9]*)/([^?]+) ]]; then
            DB_USER="${BASH_REMATCH[1]}"
            DB_PASS="${BASH_REMATCH[2]}"
            DB_HOST="${BASH_REMATCH[3]}"
            DB_PORT="${BASH_REMATCH[4]:-5432}"
            DB_NAME="${BASH_REMATCH[5]}"
            
            success "Connection string parsed successfully"
        else
            error "Invalid connection string format"
            exit 1
        fi
    else
        ask "PostgreSQL Host" "localhost" "DB_HOST"
        ask "Database Name" "status_page" "DB_NAME"
        ask "Username" "" "DB_USER"
        ask "Password" "" "DB_PASS"
        ask "Port" "5432" "DB_PORT"
    fi
}

configure_proxmox() {
    log "Proxmox Configuration"
    echo "====================="
    
    ask_yn "Enable Proxmox monitoring?" "y" "ENABLE_PROXMOX"
    
    if [ "$ENABLE_PROXMOX" = true ]; then
        ask "Proxmox Host" "localhost" "PROXMOX_HOST"
        ask "Username" "root" "PROXMOX_USER"
        ask "Password" "" "PROXMOX_PASS"
        ask "Realm" "pam" "PROXMOX_REALM"
        ask "Port" "8006" "PROXMOX_PORT"
    fi
}

configure_services() {
    log "Service Configuration"
    echo "===================="
    
    DOMAINS_CONFIG=""
    ask_yn "Add domains to monitor?" "y" "MONITOR_DOMAINS"
    
    if [ "$MONITOR_DOMAINS" = true ]; then
        domain_count=0
        while true; do
            echo
            echo "Domain #$((domain_count + 1)):"
            
            ask "Domain name (e.g., 'Main Website')" "" "DOMAIN_NAME"
            if [ -z "$DOMAIN_NAME" ]; then
                break
            fi
            
            ask "URL (with https://)" "" "DOMAIN_URL"
            ask "Expected status code" "200" "DOMAIN_CODE"
            ask "Timeout (seconds)" "10" "DOMAIN_TIMEOUT"
            ask_yn "Verify SSL certificate?" "y" "DOMAIN_SSL"
            
            ssl_check="true"
            if [ "$DOMAIN_SSL" = false ]; then
                ssl_check="false"
            fi
            
            DOMAINS_CONFIG="$DOMAINS_CONFIG    [
        'name' => '$DOMAIN_NAME',
        'url' => '$DOMAIN_URL',
        'expected_code' => $DOMAIN_CODE,
        'timeout' => $DOMAIN_TIMEOUT,
        'verify_ssl' => $ssl_check
    ],"$'\n'
            
            domain_count=$((domain_count + 1))
            
            ask_yn "Add another domain?" "n" "ADD_MORE_DOMAINS"
            if [ "$ADD_MORE_DOMAINS" = false ]; then
                break
            fi
        done
    fi
    
    SERVERS_CONFIG=""
    ask_yn "Add servers to monitor?" "y" "MONITOR_SERVERS"
    
    if [ "$MONITOR_SERVERS" = true ]; then
        server_count=0
        while true; do
            echo
            echo "Server #$((server_count + 1)):"
            
            ask "Server name" "" "SERVER_NAME"
            if [ -z "$SERVER_NAME" ]; then
                break
            fi
            
            ask "Host/IP" "" "SERVER_HOST"
            ask "Port" "" "SERVER_PORT"
            ask "Type (external/system)" "external" "SERVER_TYPE"
            ask "Timeout (seconds)" "5" "SERVER_TIMEOUT"
            
            SERVERS_CONFIG="$SERVERS_CONFIG    [
        'name' => '$SERVER_NAME',
        'host' => '$SERVER_HOST',
        'port' => $SERVER_PORT,
        'type' => '$SERVER_TYPE',
        'timeout' => $SERVER_TIMEOUT
    ],"$'\n'
            
            server_count=$((server_count + 1))
            
            ask_yn "Add another server?" "n" "ADD_MORE_SERVERS"
            if [ "$ADD_MORE_SERVERS" = false ]; then
                break
            fi
        done
    fi
    
    MINECRAFT_CONFIG=""
    ask_yn "Add Minecraft servers?" "n" "MONITOR_MINECRAFT"
    
    if [ "$MONITOR_MINECRAFT" = true ]; then
        mc_count=0
        while true; do
            echo
            echo "Minecraft Server #$((mc_count + 1)):"
            
            ask "Server name" "" "MC_NAME"
            if [ -z "$MC_NAME" ]; then
                break
            fi
            
            ask "Host/IP" "" "MC_HOST"
            ask "Port" "25565" "MC_PORT"
            ask "Timeout (seconds)" "5" "MC_TIMEOUT"
            
            MINECRAFT_CONFIG="$MINECRAFT_CONFIG    [
        'name' => '$MC_NAME',
        'host' => '$MC_HOST',
        'port' => $MC_PORT,
        'timeout' => $MC_TIMEOUT
    ],"$'\n'
            
            mc_count=$((mc_count + 1))
            
            ask_yn "Add another Minecraft server?" "n" "ADD_MORE_MC"
            if [ "$ADD_MORE_MC" = false ]; then
                break
            fi
        done
    fi
}

create_config() {
    log "Creating configuration file..."
    
    cat > "$INSTALL_DIR/config.php" << EOF
<?php
define('DB_HOST', '$DB_HOST');
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASS', '$DB_PASS');
define('DB_PORT', $DB_PORT);

\$PROXMOX_CONFIG = [
    'host' => '${PROXMOX_HOST:-localhost}',
    'username' => '${PROXMOX_USER:-root}',
    'password' => '${PROXMOX_PASS:-}',
    'realm' => '${PROXMOX_REALM:-pam}',
    'port' => ${PROXMOX_PORT:-8006},
    'enabled' => $([ "$ENABLE_PROXMOX" = true ] && echo "true" || echo "false")
];

\$DOMAINS = [
$DOMAINS_CONFIG];

\$SERVERS = [
$SERVERS_CONFIG];

\$MINECRAFT_SERVERS = [
$MINECRAFT_CONFIG];

\$NOTIFICATION_EMAIL = 'admin@example.com';
\$SMTP_HOST = 'smtp.gmail.com';
\$SMTP_PORT = 587;
\$SMTP_USERNAME = 'notifications@example.com';
\$SMTP_PASSWORD = 'your_password';

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
EOF
    
    $SUDO chown www-data:www-data "$INSTALL_DIR/config.php"
    success "Configuration file created"
}

initialize_database() {
    log "Initializing database..."
    
    cd "$INSTALL_DIR"
    $SUDO -u www-data php -r "
    require_once 'database.php';
    try {
        \$db = Database::getInstance();
        \$db->initializeTables();
        echo 'Database tables created successfully\n';
    } catch (Exception \$e) {
        echo 'Database error: ' . \$e->getMessage() . '\n';
        exit(1);
    }
    "
    
    success "Database initialized"
}

run_initial_checks() {
    log "Running initial monitoring checks..."
    
    cd "$INSTALL_DIR"
    $SUDO -u www-data php monitor.php domains 2>/dev/null || true
    $SUDO -u www-data php monitor.php servers 2>/dev/null || true
    $SUDO -u www-data php monitor.php minecraft 2>/dev/null || true
    
    if [ "$ENABLE_PROXMOX" = true ]; then
        $SUDO -u www-data php monitor.php proxmox 2>/dev/null || true
    fi
    
    success "Initial checks completed"
}

setup_ssl() {
    log "SSL Configuration"
    echo "================="
    
    ask_yn "Configure SSL certificate?" "n" "SETUP_SSL"
    
    if [ "$SETUP_SSL" = true ]; then
        ask "Domain name for SSL" "" "SSL_DOMAIN"
        
        if [ -n "$SSL_DOMAIN" ]; then
            if command -v certbot &> /dev/null; then
                $SUDO certbot --apache -d "$SSL_DOMAIN" --non-interactive --agree-tos --email admin@"$SSL_DOMAIN" || warning "SSL setup failed - continuing without SSL"
            else
                warning "Certbot not installed. Install with: apt install certbot python3-certbot-apache"
            fi
        fi
    fi
}

start_services() {
    log "Starting monitoring services..."
    
    $SUDO systemctl start status-page-monitor.timer
    $SUDO systemctl enable status-page-monitor.timer
    
    success "Monitoring services started"
}

show_completion() {
    echo
    success "Status Page installation completed!"
    echo "=================================="
    echo
    echo "📍 Installation Directory: $INSTALL_DIR"
    echo "🌐 Web Access: http://$(hostname -I | awk '{print $1}')/status"
    echo "📊 Monitored Services:"
    [ -n "$DOMAINS_CONFIG" ] && echo "   - Domains: $(echo "$DOMAINS_CONFIG" | grep -c "name")"
    [ -n "$SERVERS_CONFIG" ] && echo "   - Servers: $(echo "$SERVERS_CONFIG" | grep -c "name")"
    [ -n "$MINECRAFT_CONFIG" ] && echo "   - Minecraft: $(echo "$MINECRAFT_CONFIG" | grep -c "name")"
    [ "$ENABLE_PROXMOX" = true ] && echo "   - Proxmox: Enabled"
    echo
    echo "🔧 Useful Commands:"
    echo "   systemctl status status-page-monitor.timer"
    echo "   journalctl -u status-page-monitor.service -f"
    echo "   php $INSTALL_DIR/monitor.php test"
    echo
    echo "📝 Log File: $LOG_FILE"
}

main() {
    echo "🚀 Status Page Installer"
    echo "========================"
    echo
    
    check_root
    install_dependencies
    configure_apache
    setup_monitoring
    download_files
    configure_database
    configure_proxmox
    configure_services
    create_config
    initialize_database
    run_initial_checks
    setup_ssl
    start_services
    show_completion
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi