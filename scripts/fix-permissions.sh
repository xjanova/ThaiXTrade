#!/bin/bash

#############################################
#  TPIX TRADE - Fix Permissions Script
#  Developed by Xman Studio
#############################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }

# Detect web server user
detect_web_user() {
    if id "www-data" &>/dev/null; then
        echo "www-data"
    elif id "nginx" &>/dev/null; then
        echo "nginx"
    elif id "apache" &>/dev/null; then
        echo "apache"
    elif id "httpd" &>/dev/null; then
        echo "httpd"
    else
        echo ""
    fi
}

WEB_USER=$(detect_web_user)
CURRENT_USER=$(whoami)

echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║              TPIX TRADE Fix Permissions                         ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "Current user: ${GREEN}$CURRENT_USER${NC}"
echo -e "Web user:     ${GREEN}${WEB_USER:-not detected}${NC}"
echo ""

# Fix directory permissions
log_info "Setting directory permissions (755)..."
find . -type d -exec chmod 755 {} \; 2>/dev/null || true

# Fix file permissions
log_info "Setting file permissions (644)..."
find . -type f -exec chmod 644 {} \; 2>/dev/null || true

# Make scripts executable
log_info "Making scripts executable..."
chmod +x install.sh deploy.sh 2>/dev/null || true
chmod +x scripts/*.sh 2>/dev/null || true
chmod +x artisan 2>/dev/null || true

# Storage and cache directories need to be writable
log_info "Setting storage permissions..."
chmod -R 775 storage 2>/dev/null || true
chmod -R 775 bootstrap/cache 2>/dev/null || true

# Create necessary directories if they don't exist
log_info "Creating necessary directories..."
mkdir -p storage/app/public
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p bootstrap/cache
mkdir -p backups

# Set ownership if web user is detected and running as root
if [ -n "$WEB_USER" ] && [ "$CURRENT_USER" == "root" ]; then
    read -p "Set ownership to $WEB_USER? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        log_info "Setting ownership to $WEB_USER..."
        chown -R $WEB_USER:$WEB_USER storage bootstrap/cache
        log_success "Ownership set"
    fi
fi

# Fix public_html permissions
if [ -d "public_html" ]; then
    log_info "Setting public_html permissions..."
    chmod -R 755 public_html

    if [ -d "public_html/build" ]; then
        chmod -R 755 public_html/build
    fi
fi

# Create .gitkeep files to preserve directories
log_info "Creating .gitkeep files..."
touch storage/app/.gitkeep 2>/dev/null || true
touch storage/app/public/.gitkeep 2>/dev/null || true
touch storage/framework/cache/.gitkeep 2>/dev/null || true
touch storage/framework/sessions/.gitkeep 2>/dev/null || true
touch storage/framework/views/.gitkeep 2>/dev/null || true
touch storage/logs/.gitkeep 2>/dev/null || true
touch bootstrap/cache/.gitkeep 2>/dev/null || true

echo ""
log_success "Permissions fixed!"
echo ""

# Show permission summary
echo -e "${YELLOW}Permission Summary:${NC}"
echo "  - Directories: 755 (rwxr-xr-x)"
echo "  - Files: 644 (rw-r--r--)"
echo "  - Scripts: 755 (rwxr-xr-x)"
echo "  - Storage: 775 (rwxrwxr-x)"
echo ""

# Verify critical directories
log_info "Verifying permissions..."
if [ -w "storage" ] && [ -w "bootstrap/cache" ]; then
    log_success "Storage directories are writable"
else
    log_warning "Storage directories may not be writable. Check permissions manually."
fi
