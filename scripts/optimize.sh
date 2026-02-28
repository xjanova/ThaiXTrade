#!/bin/bash

#############################################
#  TPIX TRADE - Production Optimization
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

echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║         TPIX TRADE Production Optimization                      ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# 1. Composer optimization
log_info "Optimizing Composer autoloader..."
composer install --no-dev --optimize-autoloader --classmap-authoritative
log_success "Composer optimized"

# 2. NPM production build
log_info "Building frontend for production..."
npm ci --prefer-offline
npm run build
log_success "Frontend built"

# 3. Laravel optimization
log_info "Caching Laravel configuration..."
php artisan config:cache
log_success "Config cached"

log_info "Caching routes..."
php artisan route:cache
log_success "Routes cached"

log_info "Caching views..."
php artisan view:cache
log_success "Views cached"

log_info "Caching events..."
php artisan event:cache 2>/dev/null || true
log_success "Events cached"

# 4. Clear old caches
log_info "Clearing old caches..."
php artisan cache:clear
log_success "Cache cleared"

# 5. Optimize icons (if available)
if command -v svgo &> /dev/null; then
    log_info "Optimizing SVG icons..."
    find public_html -name "*.svg" -exec svgo {} \; 2>/dev/null || true
    log_success "SVGs optimized"
fi

# 6. Set production permissions
log_info "Setting production permissions..."
chmod -R 755 storage bootstrap/cache
chmod 640 .env 2>/dev/null || true
log_success "Permissions set"

# 7. Generate sitemap (if artisan command exists)
if php artisan list 2>/dev/null | grep -q "sitemap:generate"; then
    log_info "Generating sitemap..."
    php artisan sitemap:generate
    log_success "Sitemap generated"
fi

# 8. Warm up opcache (if running with PHP-FPM)
if php -m | grep -q "OPcache"; then
    log_info "OPcache is enabled"
    log_success "OPcache will precompile on first requests"
fi

# Summary
echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║            Optimization Complete!                              ║${NC}"
echo -e "${GREEN}╠════════════════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║  Optimizations applied:                                        ║${NC}"
echo -e "${GREEN}║    - Composer classmap-authoritative                           ║${NC}"
echo -e "${GREEN}║    - NPM production build                                      ║${NC}"
echo -e "${GREEN}║    - Laravel config/route/view caching                         ║${NC}"
echo -e "${GREEN}║    - Production file permissions                               ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Production checklist
echo -e "${YELLOW}Production Checklist:${NC}"
echo "  [ ] APP_ENV=production in .env"
echo "  [ ] APP_DEBUG=false in .env"
echo "  [ ] APP_URL uses HTTPS"
echo "  [ ] Database credentials are secure"
echo "  [ ] Redis configured for sessions/cache (optional)"
echo "  [ ] SSL certificate installed"
echo "  [ ] Cron job for scheduler: * * * * * php artisan schedule:run"
echo "  [ ] Queue worker running (if using queues)"
echo "  [ ] Backup cron configured"
echo ""
