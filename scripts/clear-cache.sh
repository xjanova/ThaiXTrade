#!/bin/bash

#############################################
#  ThaiXTrade - Clear Cache Script
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

echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║              ThaiXTrade Clear Cache                            ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Clear Laravel caches
log_info "Clearing application cache..."
php artisan cache:clear

log_info "Clearing config cache..."
php artisan config:clear

log_info "Clearing route cache..."
php artisan route:clear

log_info "Clearing view cache..."
php artisan view:clear

log_info "Clearing event cache..."
php artisan event:clear 2>/dev/null || true

log_info "Clearing compiled classes..."
php artisan clear-compiled 2>/dev/null || true

# Clear storage caches
log_info "Clearing storage caches..."
rm -rf storage/framework/cache/data/* 2>/dev/null || true
rm -rf storage/framework/views/* 2>/dev/null || true
rm -rf storage/framework/sessions/* 2>/dev/null || true

# Clear logs (optional)
if [ "$1" == "--logs" ] || [ "$1" == "-l" ]; then
    log_info "Clearing logs..."
    rm -rf storage/logs/*.log 2>/dev/null || true
    touch storage/logs/laravel.log
fi

# Clear npm cache (optional)
if [ "$1" == "--npm" ] || [ "$1" == "-n" ]; then
    log_info "Clearing npm cache..."
    npm cache clean --force 2>/dev/null || true
fi

# Clear composer cache (optional)
if [ "$1" == "--composer" ] || [ "$1" == "-c" ]; then
    log_info "Clearing composer cache..."
    composer clear-cache 2>/dev/null || true
fi

# Clear all
if [ "$1" == "--all" ] || [ "$1" == "-a" ]; then
    log_info "Clearing logs..."
    rm -rf storage/logs/*.log 2>/dev/null || true
    touch storage/logs/laravel.log

    log_info "Clearing npm cache..."
    npm cache clean --force 2>/dev/null || true

    log_info "Clearing composer cache..."
    composer clear-cache 2>/dev/null || true
fi

echo ""
log_success "All caches cleared!"
echo ""
echo -e "${YELLOW}Tip: Run 'php artisan optimize' to rebuild caches for production${NC}"
