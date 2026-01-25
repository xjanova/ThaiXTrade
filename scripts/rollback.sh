#!/bin/bash

#############################################
#  ThaiXTrade - Rollback Script
#  Developed by Xman Studio
#
#  Rollback to previous deployment
#############################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

BACKUP_DIR="backups"
RELEASES_DIR="releases"

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# List available backups
list_backups() {
    echo -e "${CYAN}Available backups:${NC}"
    echo ""

    if [ ! -d "$BACKUP_DIR" ]; then
        log_warning "No backup directory found"
        return 1
    fi

    local count=0
    for backup in $(ls -t "$BACKUP_DIR"/backup_*_env 2>/dev/null); do
        count=$((count + 1))
        local name=$(basename "$backup" _env)
        local date=$(echo "$name" | sed 's/backup_//' | sed 's/_/ /')
        echo -e "  ${GREEN}$count)${NC} $name (${date})"
    done

    if [ $count -eq 0 ]; then
        log_warning "No backups found"
        return 1
    fi

    echo ""
    echo "Total: $count backup(s)"
}

# Rollback to specific backup
rollback_to_backup() {
    local backup_name=$1

    if [ -z "$backup_name" ]; then
        log_error "Backup name required"
        return 1
    fi

    log_info "Rolling back to: $backup_name"

    # Enable maintenance mode
    php artisan down --retry=60 || true

    # Restore database
    if [ -f "$BACKUP_DIR/${backup_name}_database.sqlite" ]; then
        cp "$BACKUP_DIR/${backup_name}_database.sqlite" database/database.sqlite
        log_success "Database restored"
    fi

    # Restore .env (optional)
    read -p "Restore .env file? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        if [ -f "$BACKUP_DIR/${backup_name}_env" ]; then
            cp "$BACKUP_DIR/${backup_name}_env" .env
            log_success ".env restored"
        fi
    fi

    # Clear caches
    php artisan config:clear
    php artisan cache:clear
    php artisan view:clear

    # Disable maintenance mode
    php artisan up

    log_success "Rollback completed!"
}

# Rollback to previous git commit
rollback_git() {
    local commits=${1:-1}

    log_info "Rolling back $commits commit(s)..."

    # Show what will be rolled back
    echo -e "${YELLOW}Commits to rollback:${NC}"
    git log --oneline -n "$commits"
    echo ""

    read -p "Proceed with rollback? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_warning "Aborted"
        return 0
    fi

    # Enable maintenance mode
    php artisan down --retry=60 || true

    # Reset to previous commit
    git reset --hard HEAD~"$commits"

    # Reinstall dependencies
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build

    # Run migrations (rollback if needed)
    php artisan migrate --force

    # Clear caches
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    # Disable maintenance mode
    php artisan up

    log_success "Git rollback completed!"
}

# Show help
show_help() {
    echo "ThaiXTrade Rollback Script"
    echo ""
    echo "Usage: $0 [command] [options]"
    echo ""
    echo "Commands:"
    echo "  list              List available backups"
    echo "  backup <name>     Rollback to specific backup"
    echo "  git [n]           Rollback n git commits (default: 1)"
    echo "  help              Show this help"
    echo ""
    echo "Examples:"
    echo "  $0 list"
    echo "  $0 backup backup_20240125_143000"
    echo "  $0 git 2"
}

# Parse arguments
case "${1:-help}" in
    list)
        list_backups
        ;;
    backup)
        rollback_to_backup "$2"
        ;;
    git)
        rollback_git "${2:-1}"
        ;;
    help|-h|--help)
        show_help
        ;;
    *)
        log_error "Unknown command: $1"
        show_help
        exit 1
        ;;
esac
