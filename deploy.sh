#!/bin/bash

#############################################
#  ThaiXTrade - Deployment Script
#  Developed by Xman Studio
#  https://xmanstudio.com
#############################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuration
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
BACKUP_DIR="backups"
MAX_BACKUPS=5
MAINTENANCE_MODE=true

# Logging functions
log_info() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')] [INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] [SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] [WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] [ERROR]${NC} $1"
}

# Banner
print_banner() {
    echo -e "${PURPLE}"
    echo "╔═══════════════════════════════════════════════════════════════╗"
    echo "║             ThaiXTrade - Deployment Script                    ║"
    echo "║                  Developed by Xman Studio                     ║"
    echo "╚═══════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

# Enable maintenance mode
enable_maintenance() {
    if [ "$MAINTENANCE_MODE" = true ]; then
        log_info "Enabling maintenance mode..."
        php artisan down --retry=60 --refresh=15 || true
        log_success "Maintenance mode enabled"
    fi
}

# Disable maintenance mode
disable_maintenance() {
    if [ "$MAINTENANCE_MODE" = true ]; then
        log_info "Disabling maintenance mode..."
        php artisan up || true
        log_success "Maintenance mode disabled"
    fi
}

# Create backup
create_backup() {
    log_info "Creating backup..."

    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    BACKUP_NAME="backup_${TIMESTAMP}"

    mkdir -p "${BACKUP_DIR}"

    # Backup database
    if [ -f "database/database.sqlite" ]; then
        cp database/database.sqlite "${BACKUP_DIR}/${BACKUP_NAME}_database.sqlite"
    fi

    # Backup .env file
    if [ -f ".env" ]; then
        cp .env "${BACKUP_DIR}/${BACKUP_NAME}_env"
    fi

    # Backup vendor and node_modules references (just versions)
    if [ -f "composer.lock" ]; then
        cp composer.lock "${BACKUP_DIR}/${BACKUP_NAME}_composer.lock"
    fi

    if [ -f "package-lock.json" ]; then
        cp package-lock.json "${BACKUP_DIR}/${BACKUP_NAME}_package-lock.json"
    fi

    # Clean old backups (keep only MAX_BACKUPS)
    cd "${BACKUP_DIR}"
    ls -t backup_*_database.sqlite 2>/dev/null | tail -n +$((MAX_BACKUPS + 1)) | xargs rm -f 2>/dev/null || true
    ls -t backup_*_env 2>/dev/null | tail -n +$((MAX_BACKUPS + 1)) | xargs rm -f 2>/dev/null || true
    cd ..

    log_success "Backup created: ${BACKUP_NAME}"
}

# Pull latest code
pull_code() {
    log_info "Pulling latest code from ${DEPLOY_BRANCH}..."

    git fetch origin "${DEPLOY_BRANCH}"
    git reset --hard "origin/${DEPLOY_BRANCH}"

    log_success "Code updated to latest ${DEPLOY_BRANCH}"
}

# Install/update PHP dependencies
update_php_dependencies() {
    log_info "Updating PHP dependencies..."

    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

    log_success "PHP dependencies updated"
}

# Install/update Node.js dependencies
update_node_dependencies() {
    log_info "Updating Node.js dependencies..."

    npm ci --prefer-offline || npm install --production=false

    log_success "Node.js dependencies updated"
}

# Build frontend assets
build_assets() {
    log_info "Building frontend assets..."

    npm run build

    log_success "Frontend assets built"
}

# Run database migrations
run_migrations() {
    log_info "Running database migrations..."

    php artisan migrate --force

    log_success "Migrations completed"
}

# Clear and optimize caches
optimize_application() {
    log_info "Optimizing application..."

    php artisan config:clear
    php artisan cache:clear
    php artisan view:clear
    php artisan route:clear

    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache

    # Optimize autoloader
    composer dump-autoload --optimize

    log_success "Application optimized"
}

# Restart queue workers
restart_queue() {
    log_info "Restarting queue workers..."

    php artisan queue:restart

    log_success "Queue workers signaled to restart"
}

# Restart Octane (if running)
restart_octane() {
    if pgrep -f "artisan octane:start" > /dev/null; then
        log_info "Restarting Octane server..."
        php artisan octane:reload
        log_success "Octane server restarted"
    else
        log_warning "Octane is not running. Skipping restart."
    fi
}

# Set file permissions
set_permissions() {
    log_info "Setting file permissions..."

    chmod -R 755 storage bootstrap/cache
    chmod -R 775 storage/logs

    if [ -d "public/build" ]; then
        chmod -R 755 public/build
    fi

    log_success "Permissions set"
}

# Verify deployment
verify_deployment() {
    log_info "Verifying deployment..."

    # Check if artisan commands work
    if ! php artisan --version > /dev/null 2>&1; then
        log_error "Artisan not working properly!"
        return 1
    fi

    # Check if the app responds
    if command -v curl &> /dev/null; then
        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/health 2>/dev/null || echo "000")
        if [ "$HTTP_CODE" != "200" ] && [ "$HTTP_CODE" != "503" ]; then
            log_warning "Health check returned: ${HTTP_CODE}"
        fi
    fi

    log_success "Deployment verified"
}

# Rollback to previous version
rollback() {
    log_error "Deployment failed! Rolling back..."

    LATEST_BACKUP=$(ls -t "${BACKUP_DIR}"/backup_*_database.sqlite 2>/dev/null | head -1)

    if [ -n "$LATEST_BACKUP" ]; then
        BACKUP_PREFIX=$(basename "$LATEST_BACKUP" _database.sqlite)

        if [ -f "${BACKUP_DIR}/${BACKUP_PREFIX}_database.sqlite" ]; then
            cp "${BACKUP_DIR}/${BACKUP_PREFIX}_database.sqlite" database/database.sqlite
        fi

        log_warning "Rolled back to: ${BACKUP_PREFIX}"
    else
        log_error "No backup found for rollback!"
    fi

    disable_maintenance
    exit 1
}

# Handle errors
handle_error() {
    log_error "An error occurred during deployment!"
    rollback
}

# Main deployment flow
main() {
    print_banner

    # Set up error handling
    trap 'handle_error' ERR

    log_info "Starting deployment..."
    log_info "Branch: ${DEPLOY_BRANCH}"
    echo ""

    enable_maintenance
    create_backup
    pull_code
    update_php_dependencies
    update_node_dependencies
    build_assets
    run_migrations
    optimize_application
    set_permissions
    restart_queue
    restart_octane
    verify_deployment
    disable_maintenance

    echo ""
    echo -e "${GREEN}╔═══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║          Deployment completed successfully!                   ║${NC}"
    echo -e "${GREEN}╚═══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# Quick deploy (skip backup and some optimizations)
quick_deploy() {
    print_banner

    log_info "Starting quick deployment..."
    log_info "Branch: ${DEPLOY_BRANCH}"
    echo ""

    MAINTENANCE_MODE=false

    pull_code
    update_php_dependencies
    update_node_dependencies
    build_assets
    run_migrations
    optimize_application
    restart_queue
    restart_octane

    echo ""
    log_success "Quick deployment completed!"
}

# Show usage
show_usage() {
    echo "Usage: $0 [OPTION]"
    echo ""
    echo "Options:"
    echo "  --full, -f       Full deployment with backup (default)"
    echo "  --quick, -q      Quick deployment without backup"
    echo "  --help, -h       Show this help message"
    echo ""
    echo "Environment Variables:"
    echo "  DEPLOY_BRANCH    Branch to deploy (default: main)"
    echo ""
    echo "Examples:"
    echo "  $0                       # Full deployment"
    echo "  $0 --quick               # Quick deployment"
    echo "  DEPLOY_BRANCH=develop $0 # Deploy from develop branch"
}

# Parse arguments
case "${1:-}" in
    --quick|-q)
        quick_deploy
        ;;
    --help|-h)
        show_usage
        ;;
    --full|-f|"")
        main
        ;;
    *)
        log_error "Unknown option: $1"
        show_usage
        exit 1
        ;;
esac
