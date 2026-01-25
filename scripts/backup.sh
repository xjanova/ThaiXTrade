#!/bin/bash

#############################################
#  ThaiXTrade - Backup Script
#  Developed by Xman Studio
#
#  Create full or partial backups
#############################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Configuration
BACKUP_DIR="${BACKUP_DIR:-backups}"
MAX_BACKUPS="${MAX_BACKUPS:-10}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="backup_${TIMESTAMP}"

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Create backup directory
init_backup_dir() {
    mkdir -p "$BACKUP_DIR"
    log_info "Backup directory: $BACKUP_DIR"
}

# Backup database
backup_database() {
    log_info "Backing up database..."

    if [ -f "database/database.sqlite" ]; then
        cp database/database.sqlite "$BACKUP_DIR/${BACKUP_NAME}_database.sqlite"
        log_success "SQLite database backed up"
    elif grep -q "DB_CONNECTION=mysql" .env 2>/dev/null; then
        # MySQL backup
        source .env
        mysqldump -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" > "$BACKUP_DIR/${BACKUP_NAME}_database.sql"
        gzip "$BACKUP_DIR/${BACKUP_NAME}_database.sql"
        log_success "MySQL database backed up"
    elif grep -q "DB_CONNECTION=pgsql" .env 2>/dev/null; then
        # PostgreSQL backup
        source .env
        PGPASSWORD="$DB_PASSWORD" pg_dump -h "$DB_HOST" -U "$DB_USERNAME" "$DB_DATABASE" > "$BACKUP_DIR/${BACKUP_NAME}_database.sql"
        gzip "$BACKUP_DIR/${BACKUP_NAME}_database.sql"
        log_success "PostgreSQL database backed up"
    else
        log_warning "No database to backup"
    fi
}

# Backup environment
backup_env() {
    log_info "Backing up environment..."

    if [ -f ".env" ]; then
        cp .env "$BACKUP_DIR/${BACKUP_NAME}_env"
        log_success ".env backed up"
    fi
}

# Backup uploads/storage
backup_storage() {
    log_info "Backing up storage..."

    if [ -d "storage/app/public" ]; then
        tar -czf "$BACKUP_DIR/${BACKUP_NAME}_storage.tar.gz" -C storage/app public
        log_success "Storage backed up"
    fi
}

# Backup full application
backup_full() {
    log_info "Creating full backup..."

    # Exclude node_modules, vendor, and other large directories
    tar -czf "$BACKUP_DIR/${BACKUP_NAME}_full.tar.gz" \
        --exclude='node_modules' \
        --exclude='vendor' \
        --exclude='storage/logs/*' \
        --exclude='storage/framework/cache/*' \
        --exclude='storage/framework/sessions/*' \
        --exclude='storage/framework/views/*' \
        --exclude='.git' \
        --exclude='backups' \
        .

    log_success "Full backup created: ${BACKUP_NAME}_full.tar.gz"
}

# Backup configuration only
backup_config() {
    log_info "Backing up configuration..."

    tar -czf "$BACKUP_DIR/${BACKUP_NAME}_config.tar.gz" \
        .env \
        config/ \
        version.json \
        composer.json \
        composer.lock \
        package.json \
        package-lock.json 2>/dev/null || true

    log_success "Configuration backed up"
}

# Clean old backups
cleanup_old_backups() {
    log_info "Cleaning old backups (keeping last $MAX_BACKUPS)..."

    cd "$BACKUP_DIR"

    # Clean each type of backup
    for pattern in "*_database.sqlite" "*_database.sql.gz" "*_env" "*_storage.tar.gz" "*_full.tar.gz" "*_config.tar.gz"; do
        ls -t $pattern 2>/dev/null | tail -n +$((MAX_BACKUPS + 1)) | xargs rm -f 2>/dev/null || true
    done

    cd - > /dev/null

    log_success "Old backups cleaned"
}

# List backups
list_backups() {
    echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║                    Available Backups                           ║${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""

    if [ ! -d "$BACKUP_DIR" ]; then
        log_warning "No backup directory found"
        return
    fi

    local total_size=0
    echo -e "${YELLOW}Database Backups:${NC}"
    for f in $(ls -t "$BACKUP_DIR"/*_database* 2>/dev/null); do
        local size=$(du -h "$f" | cut -f1)
        local name=$(basename "$f")
        echo "  - $name ($size)"
        total_size=$((total_size + $(du -k "$f" | cut -f1)))
    done

    echo ""
    echo -e "${YELLOW}Environment Backups:${NC}"
    for f in $(ls -t "$BACKUP_DIR"/*_env 2>/dev/null); do
        local size=$(du -h "$f" | cut -f1)
        local name=$(basename "$f")
        echo "  - $name ($size)"
    done

    echo ""
    echo -e "${YELLOW}Storage Backups:${NC}"
    for f in $(ls -t "$BACKUP_DIR"/*_storage* 2>/dev/null); do
        local size=$(du -h "$f" | cut -f1)
        local name=$(basename "$f")
        echo "  - $name ($size)"
    done

    echo ""
    echo -e "${YELLOW}Full Backups:${NC}"
    for f in $(ls -t "$BACKUP_DIR"/*_full* 2>/dev/null); do
        local size=$(du -h "$f" | cut -f1)
        local name=$(basename "$f")
        echo "  - $name ($size)"
    done

    echo ""
    echo -e "Total backup size: ${GREEN}$(du -sh "$BACKUP_DIR" 2>/dev/null | cut -f1)${NC}"
}

# Quick backup (db + env)
quick_backup() {
    echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║              ThaiXTrade Quick Backup                           ║${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""

    init_backup_dir
    backup_database
    backup_env
    cleanup_old_backups

    echo ""
    log_success "Quick backup completed: $BACKUP_NAME"
}

# Full backup
full_backup() {
    echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║              ThaiXTrade Full Backup                            ║${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""

    init_backup_dir
    backup_database
    backup_env
    backup_storage
    backup_config
    backup_full
    cleanup_old_backups

    echo ""
    log_success "Full backup completed: $BACKUP_NAME"
    echo -e "Location: ${GREEN}$BACKUP_DIR/${NC}"
}

# Show help
show_help() {
    echo "ThaiXTrade Backup Script"
    echo ""
    echo "Usage: $0 [command]"
    echo ""
    echo "Commands:"
    echo "  quick      Quick backup (database + .env)"
    echo "  full       Full backup (everything)"
    echo "  db         Database only"
    echo "  storage    Storage/uploads only"
    echo "  config     Configuration files only"
    echo "  list       List available backups"
    echo "  clean      Clean old backups"
    echo "  help       Show this help"
    echo ""
    echo "Environment Variables:"
    echo "  BACKUP_DIR    Backup directory (default: backups)"
    echo "  MAX_BACKUPS   Maximum backups to keep (default: 10)"
    echo ""
    echo "Examples:"
    echo "  $0 quick"
    echo "  $0 full"
    echo "  MAX_BACKUPS=5 $0 clean"
}

# Parse arguments
case "${1:-quick}" in
    quick)
        quick_backup
        ;;
    full)
        full_backup
        ;;
    db|database)
        init_backup_dir
        backup_database
        ;;
    storage)
        init_backup_dir
        backup_storage
        ;;
    config)
        init_backup_dir
        backup_config
        ;;
    list)
        list_backups
        ;;
    clean)
        cleanup_old_backups
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
