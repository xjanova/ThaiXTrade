#!/bin/bash

#########################################################
# TPIX TRADE - Smart Automated Deployment Script
# Developed by Xman Studio (https://xmanstudio.com)
# Features:
#   - Smart migration handling (skip existing tables)
#   - Intelligent seeding (skip existing data)
#   - Detailed error logging and reporting
#   - Automatic rollback on failure
#   - DirectAdmin/cPanel/VPS compatible
#########################################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m'

# Configuration
BRANCH=${1:-main}
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="storage/backups"
LOG_DIR="storage/logs/deploy"
LOG_FILE="$LOG_DIR/deploy_${TIMESTAMP}.log"
ERROR_LOG="$LOG_DIR/error_${TIMESTAMP}.log"

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Create log directories
mkdir -p "$LOG_DIR"
mkdir -p "$BACKUP_DIR"

# Logging functions
log() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    echo "$message" >> "$LOG_FILE"
    echo -e "$2$1${NC}"
}

log_error() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1"
    echo "$message" >> "$LOG_FILE"
    echo "$message" >> "$ERROR_LOG"
    echo -e "${RED}✗ $1${NC}"
}

log_error_detail() {
    local message="$1"
    echo "$message" >> "$ERROR_LOG"
    echo "$message" >> "$LOG_FILE"
}

# Functions
print_header() {
    echo -e "\n${CYAN}╔════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║   🚀 TPIX TRADE Deployment Script 🚀           ║${NC}"
    echo -e "${CYAN}║     Smart Migration & Seeding Support          ║${NC}"
    echo -e "${CYAN}║     Developed by Xman Studio                   ║${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════════════╝${NC}\n"
    log "Deployment started" ""
}

print_step() {
    log "STEP: $1" "${BLUE}"
    echo -e "\n${BLUE}━━━ $1 ━━━${NC}"
}

print_success() {
    log "SUCCESS: $1" "${GREEN}"
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    log_error "$1"
}

print_warning() {
    log "WARNING: $1" "${YELLOW}"
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    log "INFO: $1" "${PURPLE}"
    echo -e "${PURPLE}ℹ $1${NC}"
}

# Generate error report
generate_error_report() {
    local step="$1"
    local error_message="$2"
    local error_output="$3"

    echo "" >> "$ERROR_LOG"
    echo "═══════════════════════════════════════════════════════════" >> "$ERROR_LOG"
    echo "ERROR REPORT - $(date '+%Y-%m-%d %H:%M:%S')" >> "$ERROR_LOG"
    echo "═══════════════════════════════════════════════════════════" >> "$ERROR_LOG"
    echo "" >> "$ERROR_LOG"
    echo "Step: $step" >> "$ERROR_LOG"
    echo "Branch: $BRANCH" >> "$ERROR_LOG"
    echo "Commit: $(git rev-parse --short HEAD 2>/dev/null || echo 'N/A')" >> "$ERROR_LOG"
    echo "Environment: $(grep "^APP_ENV=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo 'N/A')" >> "$ERROR_LOG"
    echo "" >> "$ERROR_LOG"
    echo "Error Message:" >> "$ERROR_LOG"
    echo "$error_message" >> "$ERROR_LOG"
    echo "" >> "$ERROR_LOG"
    echo "Error Output:" >> "$ERROR_LOG"
    echo "---" >> "$ERROR_LOG"
    echo "$error_output" >> "$ERROR_LOG"
    echo "---" >> "$ERROR_LOG"
    echo "" >> "$ERROR_LOG"

    # System info
    echo "System Information:" >> "$ERROR_LOG"
    echo "  PHP Version: $(php -v 2>/dev/null | head -1 || echo 'N/A')" >> "$ERROR_LOG"
    echo "  Composer: $(composer --version 2>/dev/null | head -1 || echo 'N/A')" >> "$ERROR_LOG"
    echo "  Node: $(node -v 2>/dev/null || echo 'N/A')" >> "$ERROR_LOG"
    echo "  NPM: $(npm -v 2>/dev/null || echo 'N/A')" >> "$ERROR_LOG"
    echo "" >> "$ERROR_LOG"

    # Database info
    echo "Database Information:" >> "$ERROR_LOG"
    echo "  Connection: $(grep "^DB_CONNECTION=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo 'N/A')" >> "$ERROR_LOG"
    echo "  Host: $(grep "^DB_HOST=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo 'N/A')" >> "$ERROR_LOG"
    echo "  Port: $(grep "^DB_PORT=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo 'N/A')" >> "$ERROR_LOG"
    echo "  Database: $(grep "^DB_DATABASE=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo 'N/A')" >> "$ERROR_LOG"
    echo "" >> "$ERROR_LOG"

    # Recent Laravel log
    if [ -f "storage/logs/laravel.log" ]; then
        echo "Recent Laravel Logs (last 50 lines):" >> "$ERROR_LOG"
        echo "---" >> "$ERROR_LOG"
        tail -50 storage/logs/laravel.log >> "$ERROR_LOG" 2>/dev/null || echo "Could not read Laravel log" >> "$ERROR_LOG"
        echo "---" >> "$ERROR_LOG"
    fi

    echo "" >> "$ERROR_LOG"
    echo "═══════════════════════════════════════════════════════════" >> "$ERROR_LOG"
}

# Sanitize .env file to fix common issues
sanitize_env_file() {
    print_step "Sanitizing Environment File"

    if [ ! -f .env ]; then
        print_warning ".env file not found, skipping sanitization"
        return 0
    fi

    # Create a backup
    cp .env .env.backup.${TIMESTAMP}
    print_info "Created backup: .env.backup.${TIMESTAMP}"

    # Fix common .env issues using awk
    awk '
    BEGIN { FS="="; OFS="=" }
    {
        if ($0 ~ /^[[:space:]]*$/ || $0 ~ /^[[:space:]]*#/) {
            print $0
            next
        }
        if (NF >= 2) {
            key = $1
            value = substr($0, length($1) + 2)
            gsub(/[[:space:]]+$/, "", value)
            gsub(/\r/, "", value)
            gsub(/\n/, "", value)
            print key OFS value
        } else {
            print $0
        }
    }
    ' .env > .env.tmp && mv .env.tmp .env

    # Check for duplicate keys and keep only the first occurrence
    awk '
    BEGIN { FS="="; OFS="=" }
    !seen[$1]++ {
        print $0
    }
    ' .env > .env.tmp && mv .env.tmp .env

    print_success "Environment file sanitized"

    # Verify the file is valid
    set +e
    PHP_CHECK=$(php -r "
        if (file_exists('.env')) {
            \$lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            \$valid = true;
            foreach (\$lines as \$line) {
                \$line = trim(\$line);
                if (empty(\$line) || \$line[0] === '#') continue;
                if (strpos(\$line, '=') === false) {
                    echo 'invalid: Line without = found: ' . \$line;
                    \$valid = false;
                    break;
                }
            }
            if (\$valid) echo 'valid';
        } else {
            echo 'invalid: .env file not found';
        }
    " 2>&1)
    set -e

    if echo "$PHP_CHECK" | grep -q "invalid"; then
        print_warning "Environment file may have issues: $PHP_CHECK"
        print_info "Backup available at: .env.backup.${TIMESTAMP}"
    else
        print_success "Environment file validation passed"
    fi
}

# Check and generate APP_KEY if missing
check_app_key() {
    print_step "Checking Application Key"

    if [ ! -f .env ]; then
        print_warning ".env file not found, skipping APP_KEY check"
        return 0
    fi

    APP_KEY=$(grep "^APP_KEY=" .env | cut -d'=' -f2 | tr -d '[:space:]' || echo "")

    if [ -z "$APP_KEY" ]; then
        print_warning "APP_KEY is missing or empty"
        print_info "Generating new application key..."

        set +e
        KEYGEN_OUTPUT=$(php artisan key:generate --force 2>&1)
        KEYGEN_EXIT=$?
        set -e

        if [ $KEYGEN_EXIT -eq 0 ]; then
            print_success "Application key generated successfully"
            NEW_KEY=$(grep "^APP_KEY=" .env | cut -d'=' -f2 || echo "")
            if [ -n "$NEW_KEY" ]; then
                MASKED_KEY="${NEW_KEY:0:10}..."
                print_info "New key (masked): $MASKED_KEY"
            fi
        else
            print_error "Failed to generate application key"
            log_error_detail "Key generation output: $KEYGEN_OUTPUT"
            echo "$KEYGEN_OUTPUT"
            return 1
        fi
    else
        MASKED_KEY="${APP_KEY:0:10}..."
        print_success "Application key exists (masked): $MASKED_KEY"
    fi
}

# Check environment
check_environment() {
    print_step "Checking Environment"

    if [ ! -f .env ]; then
        print_error ".env file not found"
        print_info "Please run ./install.sh first"
        exit 1
    fi

    if grep -q "^APP_ENV=production" .env; then
        print_warning "Deploying to PRODUCTION environment"
        print_info "Continuing deployment automatically..."
    else
        APP_ENV=$(grep "^APP_ENV=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo "unknown")
        print_info "Deploying to $APP_ENV environment"
    fi

    print_success "Environment check passed"
}

# Create database backup before migration
backup_database() {
    print_step "Backing Up Database"

    if [ "${SKIP_BACKUP:-0}" = "1" ]; then
        print_info "Backup skipped (--no-backup flag)"
        return 0
    fi

    DB_CONNECTION=$(grep "^DB_CONNECTION=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo "")

    if [ "$DB_CONNECTION" = "mysql" ]; then
        DB_HOST=$(grep "^DB_HOST=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo "127.0.0.1")
        DB_PORT=$(grep "^DB_PORT=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo "3306")
        DB_DATABASE=$(grep "^DB_DATABASE=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo "")
        DB_USERNAME=$(grep "^DB_USERNAME=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo "")
        DB_PASSWORD=$(grep "^DB_PASSWORD=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo "")

        if [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ]; then
            print_warning "Database credentials incomplete, skipping backup"
            return 0
        fi

        BACKUP_FILE="$BACKUP_DIR/backup_${TIMESTAMP}.sql"

        if command -v mysqldump >/dev/null 2>&1; then
            print_info "Creating MySQL backup..."

            set +e
            BACKUP_OUTPUT=$(mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" 2>&1)
            BACKUP_EXIT=$?
            set -e

            if [ $BACKUP_EXIT -eq 0 ]; then
                echo "$BACKUP_OUTPUT" > "$BACKUP_FILE"
                print_success "Database backed up to $BACKUP_FILE"
            else
                print_warning "Could not create backup: $BACKUP_OUTPUT"
                log_error_detail "Backup failed: $BACKUP_OUTPUT"
            fi
        else
            print_warning "mysqldump not available, skipping backup"
        fi
    elif [ "$DB_CONNECTION" = "sqlite" ]; then
        if [ -f database/database.sqlite ]; then
            cp database/database.sqlite "$BACKUP_DIR/backup_${TIMESTAMP}.sqlite"
            print_success "SQLite database backed up"
        fi
    else
        print_warning "Unknown database type ($DB_CONNECTION), skipping backup"
    fi
}

# Cleanup old backup files (older than 2 days)
cleanup_old_backups() {
    print_step "Cleaning Up Old Backups"

    local DELETED_COUNT=0

    if ls .env.backup.* >/dev/null 2>&1; then
        while IFS= read -r file; do
            if [ -f "$file" ]; then
                rm -f "$file"
                print_info "Deleted: $file"
                DELETED_COUNT=$((DELETED_COUNT + 1))
            fi
        done < <(find . -maxdepth 1 -name ".env.backup.*" -type f -mtime +2)
    fi

    if [ -d "$BACKUP_DIR" ]; then
        while IFS= read -r file; do
            if [ -f "$file" ]; then
                rm -f "$file"
                print_info "Deleted: $file"
                DELETED_COUNT=$((DELETED_COUNT + 1))
            fi
        done < <(find "$BACKUP_DIR" -type f \( -name "backup_*.sql" -o -name "backup_*.sqlite" \) -mtime +2)
    fi

    if [ $DELETED_COUNT -eq 0 ]; then
        print_success "No old backup files to clean (keeping files newer than 2 days)"
    else
        print_success "Deleted $DELETED_COUNT old backup file(s)"
    fi
}

# Enable maintenance mode
enable_maintenance() {
    print_step "Enabling Maintenance Mode"
    php artisan down --retry=60 2>&1 || true
    print_success "Application is now in maintenance mode"
}

# Disable maintenance mode
disable_maintenance() {
    print_step "Disabling Maintenance Mode"
    php artisan up 2>&1
    print_success "Application is now live"
}

# Pull latest code
pull_code() {
    print_step "Pulling Latest Code"

    if [ -d .git ]; then
        print_info "Fetching from repository..."

        set +e
        GIT_OUTPUT=$(git fetch origin 2>&1)
        GIT_EXIT=$?
        set -e

        if [ $GIT_EXIT -ne 0 ]; then
            print_error "Git fetch failed"
            generate_error_report "pull_code" "Git fetch failed" "$GIT_OUTPUT"
            return 1
        fi

        print_info "Pulling branch: $BRANCH"

        set +e
        GIT_OUTPUT=$(git pull origin "$BRANCH" 2>&1)
        GIT_EXIT=$?
        set -e

        if [ $GIT_EXIT -ne 0 ]; then
            print_error "Git pull failed"
            generate_error_report "pull_code" "Git pull failed for branch $BRANCH" "$GIT_OUTPUT"
            return 1
        fi

        CURRENT_COMMIT=$(git rev-parse --short HEAD)
        print_success "Updated to commit: $CURRENT_COMMIT"
    else
        print_warning "Not a git repository, skipping code pull"
    fi
}

# Install/Update dependencies
update_dependencies() {
    print_step "Updating Dependencies"

    # Composer
    print_info "Updating PHP dependencies..."

    set +e
    COMPOSER_OUTPUT=$(composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev 2>&1)
    COMPOSER_EXIT=$?
    set -e

    if [ $COMPOSER_EXIT -ne 0 ]; then
        print_warning "Composer install failed, trying with --no-scripts"
        set +e
        COMPOSER_OUTPUT=$(composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-scripts 2>&1)
        COMPOSER_EXIT=$?
        set -e

        if [ $COMPOSER_EXIT -ne 0 ]; then
            print_error "Composer install failed"
            generate_error_report "update_dependencies" "Composer install failed" "$COMPOSER_OUTPUT"
            echo "$COMPOSER_OUTPUT"
            return 1
        fi
    fi

    print_success "Composer dependencies updated"

    # NPM
    if command -v npm >/dev/null 2>&1; then
        print_info "Updating Node.js dependencies..."

        set +e
        if [ -f package-lock.json ]; then
            NPM_OUTPUT=$(npm ci 2>&1)
        else
            NPM_OUTPUT=$(npm install 2>&1)
        fi
        NPM_EXIT=$?
        set -e

        if [ $NPM_EXIT -ne 0 ]; then
            print_warning "NPM install had issues (non-fatal)"
            log_error_detail "NPM install output: $NPM_OUTPUT"
        else
            print_success "NPM dependencies updated"
        fi
    else
        print_warning "NPM not available, skipping Node.js dependencies"
    fi
}

# Smart database migrations
run_migrations() {
    print_step "Running Smart Database Migrations"

    php artisan config:clear 2>/dev/null || true

    print_info "Checking for pending migrations..."

    set +e
    MIGRATION_STATUS=$(php artisan migrate:status 2>&1)
    MIGRATION_STATUS_EXIT=$?
    PENDING_COUNT=$(echo "$MIGRATION_STATUS" | grep -c "Pending" || echo "0")
    set -e

    if [ $MIGRATION_STATUS_EXIT -ne 0 ]; then
        if echo "$MIGRATION_STATUS" | grep -q "Migration table not found\|Base table or view not found.*migrations"; then
            print_warning "Migration table not found - this appears to be a fresh database"
            print_info "Installing migrations table..."

            set +e
            INSTALL_OUTPUT=$(php artisan migrate:install 2>&1)
            INSTALL_EXIT=$?
            set -e

            if [ $INSTALL_EXIT -ne 0 ]; then
                print_error "Failed to install migrations table"
                generate_error_report "run_migrations" "migrate:install failed" "$INSTALL_OUTPUT"
                echo "$INSTALL_OUTPUT"
                return 1
            fi

            print_success "Migrations table created"

            set +e
            MIGRATION_STATUS=$(php artisan migrate:status 2>&1)
            MIGRATION_STATUS_EXIT=$?
            PENDING_COUNT=$(echo "$MIGRATION_STATUS" | grep -c "Pending" || echo "0")
            set -e
        else
            print_error "Could not check migration status"
            generate_error_report "run_migrations" "migrate:status failed" "$MIGRATION_STATUS"
            echo "$MIGRATION_STATUS"
            return 1
        fi
    fi

    if [ "$PENDING_COUNT" = "0" ]; then
        print_success "No pending migrations"
        return 0
    fi

    print_warning "Found $PENDING_COUNT pending migration(s)"

    set +e
    MIGRATION_OUTPUT=$(php artisan migrate --force 2>&1)
    MIGRATION_EXIT=$?
    set -e

    echo "$MIGRATION_OUTPUT"
    log_error_detail "Migration output: $MIGRATION_OUTPUT"

    if [ $MIGRATION_EXIT -eq 0 ]; then
        print_success "All migrations completed successfully"
        return 0
    fi

    if echo "$MIGRATION_OUTPUT" | grep -q "already exists"; then
        print_warning "Some tables already exist, attempting to sync..."

        FAILED_TABLE=$(echo "$MIGRATION_OUTPUT" | grep -oP "Table '\K[^']+" | head -1)
        FAILED_MIGRATION_FILE=$(echo "$MIGRATION_OUTPUT" | grep -oP "\d{4}_\d{2}_\d{2}_\d+_\w+" | head -1)

        print_info "Table '$FAILED_TABLE' already exists"
        print_info "Migration file: $FAILED_MIGRATION_FILE"

        generate_error_report "run_migrations" "Table already exists: $FAILED_TABLE" "$MIGRATION_OUTPUT"

        print_error "Migration failed. Please check error log: $ERROR_LOG"
        return 1
    fi

    generate_error_report "run_migrations" "Migration failed with unknown error" "$MIGRATION_OUTPUT"
    print_error "Migration failed with unknown error"
    print_info "Check error log: $ERROR_LOG"
    return 1
}

# Smart seeding
run_smart_seeding() {
    print_step "Running Smart Database Seeding"

    if [ ! -d "database/seeders" ]; then
        print_info "No seeders directory found, skipping"
        return 0
    fi

    if [ -f "database/seeders/DatabaseSeeder.php" ]; then
        print_info "Checking if seeding is needed..."

        set +e
        SHOULD_SEED=$(php artisan tinker --execute="echo \App\Models\User::count() == 0 ? 'yes' : 'no';" 2>/dev/null | tail -1)
        set -e

        if [ "$SHOULD_SEED" = "yes" ] || [ "${FORCE_SEED:-0}" = "1" ]; then
            print_info "Running DatabaseSeeder..."

            set +e
            SEED_OUTPUT=$(php artisan db:seed --force 2>&1)
            SEED_EXIT=$?
            set -e

            if [ $SEED_EXIT -ne 0 ]; then
                print_warning "Seeding had issues (non-fatal)"
                log_error_detail "Seeding output: $SEED_OUTPUT"
            else
                print_success "Seeding completed"
            fi
        else
            print_info "Data already exists, skipping seeding"
        fi
    else
        print_info "No DatabaseSeeder found, skipping"
    fi

    # Run changed seeders
    run_changed_seeders
}

# Smart seeder detection
SEEDER_HASH_FILE="storage/.seeder_hashes"

run_changed_seeders() {
    print_step "Detecting Changed/New Seeders"

    if [ ! -d "database/seeders" ]; then
        print_info "No seeders directory found, skipping"
        return 0
    fi

    if [ ! -f "$SEEDER_HASH_FILE" ]; then
        touch "$SEEDER_HASH_FILE"
        print_info "Created seeder hash tracking file"
    fi

    local CHANGED_SEEDERS=()
    local NEW_SEEDERS=()

    for SEEDER_FILE in database/seeders/*Seeder.php; do
        [ -f "$SEEDER_FILE" ] || continue

        SEEDER_NAME=$(basename "$SEEDER_FILE" .php)

        if [[ "$SEEDER_NAME" == "DatabaseSeeder" ]]; then
            continue
        fi

        CURRENT_HASH=$(md5sum "$SEEDER_FILE" | cut -d' ' -f1)
        STORED_HASH=$(grep "^${SEEDER_NAME}:" "$SEEDER_HASH_FILE" 2>/dev/null | cut -d':' -f2 || echo "")

        if [ -z "$STORED_HASH" ]; then
            NEW_SEEDERS+=("$SEEDER_NAME")
            print_info "New seeder detected: $SEEDER_NAME"
        elif [ "$CURRENT_HASH" != "$STORED_HASH" ]; then
            CHANGED_SEEDERS+=("$SEEDER_NAME")
            print_info "Changed seeder detected: $SEEDER_NAME"
        fi
    done

    if [ ${#NEW_SEEDERS[@]} -gt 0 ]; then
        print_info "Running ${#NEW_SEEDERS[@]} new seeder(s)..."
        for SEEDER in "${NEW_SEEDERS[@]}"; do
            run_single_seeder "$SEEDER"
        done
    fi

    if [ ${#CHANGED_SEEDERS[@]} -gt 0 ]; then
        print_info "Running ${#CHANGED_SEEDERS[@]} changed seeder(s)..."
        for SEEDER in "${CHANGED_SEEDERS[@]}"; do
            run_single_seeder "$SEEDER"
        done
    fi

    if [ ${#NEW_SEEDERS[@]} -eq 0 ] && [ ${#CHANGED_SEEDERS[@]} -eq 0 ]; then
        print_success "No seeder changes detected"
    fi

    update_seeder_hashes
}

# Run a single seeder
run_single_seeder() {
    local SEEDER_NAME="$1"
    local SEEDER_FILE="database/seeders/${SEEDER_NAME}.php"

    if [ ! -f "$SEEDER_FILE" ]; then
        print_warning "Seeder file not found: $SEEDER_FILE"
        return 1
    fi

    print_info "Running $SEEDER_NAME..."

    set +e
    SEED_OUTPUT=$(php artisan db:seed --class="$SEEDER_NAME" --force 2>&1)
    SEED_EXIT=$?
    set -e

    if [ $SEED_EXIT -ne 0 ]; then
        print_warning "$SEEDER_NAME failed"
        log_error_detail "$SEEDER_NAME output: $SEED_OUTPUT"

        if echo "$SEED_OUTPUT" | grep -q "Table .* doesn't exist"; then
            print_info "Table not yet created, will retry on next deployment"
        elif echo "$SEED_OUTPUT" | grep -q "Unknown column"; then
            print_info "Schema mismatch detected, check migrations"
        else
            echo "$SEED_OUTPUT"
        fi
        return 1
    else
        print_success "$SEEDER_NAME completed"
        return 0
    fi
}

# Update the seeder hash file
update_seeder_hashes() {
    print_info "Updating seeder hash tracking..."

    local NEW_HASHES=""

    for SEEDER_FILE in database/seeders/*Seeder.php; do
        [ -f "$SEEDER_FILE" ] || continue

        SEEDER_NAME=$(basename "$SEEDER_FILE" .php)

        if [[ "$SEEDER_NAME" == "DatabaseSeeder" ]]; then
            continue
        fi

        CURRENT_HASH=$(md5sum "$SEEDER_FILE" | cut -d' ' -f1)
        NEW_HASHES="${NEW_HASHES}${SEEDER_NAME}:${CURRENT_HASH}\n"
    done

    echo -e "$NEW_HASHES" > "$SEEDER_HASH_FILE"
    print_success "Seeder hashes updated"
}

# Build assets
build_assets() {
    print_step "Building Frontend Assets"

    if command -v npm >/dev/null 2>&1; then
        print_info "Building production assets..."

        set +e
        BUILD_OUTPUT=$(npm run build 2>&1)
        BUILD_EXIT=$?
        set -e

        if [ $BUILD_EXIT -ne 0 ]; then
            print_error "Asset build failed"
            generate_error_report "build_assets" "npm run build failed" "$BUILD_OUTPUT"
            echo "$BUILD_OUTPUT"
            return 1
        fi

        print_success "Assets built successfully"
    else
        print_warning "NPM not available, skipping asset build"
    fi
}

# Clear and optimize cache
optimize_application() {
    print_step "Optimizing Application"

    print_info "Clearing caches..."
    php artisan cache:clear 2>&1
    php artisan config:clear 2>&1
    php artisan route:clear 2>&1
    php artisan view:clear 2>&1
    print_success "Caches cleared"

    # Clear PHP OPcache
    print_info "Clearing PHP OPcache..."
    set +e
    if command -v php >/dev/null 2>&1; then
        OPCACHE_OUTPUT=$(php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache cleared via CLI'; } else { echo 'OPcache not enabled or not available'; }" 2>&1)
        print_info "$OPCACHE_OUTPUT"
    fi
    set -e

    # Optimize for production
    if grep -q "APP_ENV=production" .env; then
        print_info "Optimizing for production..."

        set +e
        php artisan config:cache 2>&1 || print_warning "config:cache had issues"
        php artisan route:cache 2>&1 || print_warning "route:cache had issues"
        php artisan view:cache 2>&1 || print_warning "view:cache had issues"
        php artisan event:cache 2>&1 || true
        set -e

        print_success "Application optimized for production"
    fi

    print_info "Optimizing autoloader..."
    composer dump-autoload --optimize 2>&1
    print_success "Autoloader optimized"
}

# Fix permissions
fix_permissions() {
    print_step "Fixing File Permissions"

    chmod -R 775 storage bootstrap/cache 2>&1
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

    # TPIX TRADE uses public_html/ instead of public/
    if [ -d "public_html/build" ]; then
        chmod -R 755 public_html/build
    fi

    print_success "Permissions fixed"
}

# Queue and workers
restart_queue() {
    print_step "Restarting Queue Workers"
    php artisan queue:restart 2>&1 || print_warning "queue:restart had issues"
    print_success "Queue workers will restart on next job"
}

# Verify production security settings
verify_production_security() {
    print_step "Verifying Production Security"

    local APP_ENV=$(grep "^APP_ENV=" .env 2>/dev/null | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo "production")

    if [[ "$APP_ENV" =~ ^(local|development|testing|dev)$ ]]; then
        print_info "Environment: $APP_ENV (skipping production security checks)"
        return 0
    fi

    print_info "Environment: $APP_ENV (performing security checks)"
    local SECURITY_ISSUES=0

    if grep -q "^APP_DEBUG=true" .env; then
        print_error "APP_DEBUG is enabled! This exposes sensitive information."
        SECURITY_ISSUES=$((SECURITY_ISSUES + 1))
    else
        print_success "APP_DEBUG is disabled"
    fi

    if ! grep -q "^APP_ENV=production" .env; then
        print_warning "APP_ENV is '$APP_ENV' (not production)"
    else
        print_success "APP_ENV is set to production"
    fi

    if grep -q "TELESCOPE_ENABLED=true" .env 2>/dev/null; then
        print_warning "Laravel Telescope is enabled in production"
    fi

    if grep -q "DEBUGBAR_ENABLED=true" .env 2>/dev/null; then
        print_warning "Laravel Debugbar is enabled in production"
    fi

    if [ $SECURITY_ISSUES -gt 0 ]; then
        print_error "Found $SECURITY_ISSUES critical security issue(s)!"
        return 1
    else
        print_success "Security check passed"
    fi
}

# Post-deployment tasks
post_deployment() {
    print_step "Post-Deployment Tasks"

    php artisan auth:clear-resets 2>/dev/null || true
    print_success "Cleared expired tokens"

    # TPIX TRADE uses public_html/
    if [ ! -L "public_html/storage" ]; then
        php artisan storage:link 2>/dev/null || true
        print_success "Storage linked"
    fi
}

# Health check
health_check() {
    print_step "Running Health Check"

    local HEALTH_ISSUES=0

    # Check database connection
    print_info "Checking database connection (timeout: 15s)..."
    set +e
    if command -v timeout >/dev/null 2>&1; then
        DB_CHECK=$(timeout 15 php artisan tinker --execute="try { \DB::connection()->getPdo(); echo 'ok'; } catch(\Exception \$e) { echo 'fail: ' . \$e->getMessage(); }" 2>/dev/null | tail -1)
        DB_EXIT=$?
        if [ $DB_EXIT -eq 124 ]; then
            DB_CHECK="fail: timeout after 15 seconds"
        fi
    else
        DB_CHECK=$(php artisan tinker --execute="try { \DB::connection()->getPdo(); echo 'ok'; } catch(\Exception \$e) { echo 'fail: ' . \$e->getMessage(); }" 2>/dev/null | tail -1)
    fi
    set -e

    if [[ "$DB_CHECK" == "ok" ]]; then
        print_success "Database connection is working"
    elif [[ "$DB_CHECK" == *"timeout"* ]]; then
        print_warning "Database connection timed out"
    else
        print_error "Database connection failed: $DB_CHECK"
        HEALTH_ISSUES=$((HEALTH_ISSUES + 1))
    fi

    # Check if application is accessible
    if command -v curl >/dev/null 2>&1; then
        APP_URL=$(grep "^APP_URL=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo "")

        if [ -n "$APP_URL" ]; then
            set +e
            HTTP_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$APP_URL" 2>/dev/null)
            set -e

            if [ "$HTTP_RESPONSE" = "200" ] || [ "$HTTP_RESPONSE" = "302" ] || [ "$HTTP_RESPONSE" = "301" ]; then
                print_success "Application is accessible at $APP_URL (HTTP $HTTP_RESPONSE)"
            elif [ "$HTTP_RESPONSE" = "000" ]; then
                print_warning "Could not reach $APP_URL"
                HEALTH_ISSUES=$((HEALTH_ISSUES + 1))
            elif [[ "$HTTP_RESPONSE" =~ ^[45][0-9][0-9]$ ]]; then
                print_error "Application returned HTTP $HTTP_RESPONSE"
                HEALTH_ISSUES=$((HEALTH_ISSUES + 1))

                if [ -f "storage/logs/laravel.log" ]; then
                    echo ""
                    echo -e "${RED}━━━ Recent Error Log (Last 30 lines) ━━━${NC}"
                    tail -30 storage/logs/laravel.log | grep -A 5 -i "error\|exception\|fatal" || tail -30 storage/logs/laravel.log
                    echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
                fi
            fi
        fi
    fi

    # Check storage permissions
    if [ -w "storage/logs" ]; then
        print_success "Storage is writable"
    else
        print_error "Storage is not writable"
        HEALTH_ISSUES=$((HEALTH_ISSUES + 1))
    fi

    if [ $HEALTH_ISSUES -gt 0 ]; then
        print_warning "Health check completed with $HEALTH_ISSUES issue(s)"
        echo -e "${YELLOW}━━━ Troubleshooting Tips ━━━${NC}"
        echo -e "1. Check Laravel logs: ${PURPLE}tail -50 storage/logs/laravel.log${NC}"
        echo -e "2. Check deploy logs: ${PURPLE}tail -50 $LOG_FILE${NC}"
        echo -e "3. Clear all caches: ${PURPLE}php artisan cache:clear && php artisan config:clear${NC}"
    else
        print_success "Health check completed - all systems operational"
    fi
}

# Handle deployment failure
on_error() {
    local exit_code=$?
    print_error "Deployment failed! (Exit code: $exit_code)"

    php artisan up 2>/dev/null || true

    echo ""
    echo -e "${RED}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}                    DEPLOYMENT FAILED                        ${NC}"
    echo -e "${RED}═══════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "${YELLOW}Error logs saved to:${NC}"
    echo -e "  ${PURPLE}Full log:${NC}  $LOG_FILE"
    echo -e "  ${PURPLE}Error log:${NC} $ERROR_LOG"
    echo ""
    echo -e "${YELLOW}To retry deployment:${NC}"
    echo -e "  ./deploy.sh $BRANCH"
    echo ""

    exit 1
}

# Update deploy.sh script to latest version
update_deploy_script() {
    if [ "${DEPLOY_SCRIPT_UPDATED}" = "true" ]; then
        return 0
    fi

    if ! git rev-parse --git-dir >/dev/null 2>&1; then
        return 0
    fi

    local CURRENT_BRANCH=$(git branch --show-current 2>/dev/null || echo "")
    if [ -z "$CURRENT_BRANCH" ]; then
        return 0
    fi

    if git diff --name-only | grep -q "^deploy.sh$"; then
        return 0
    fi

    if ! git fetch origin "$CURRENT_BRANCH" 2>/dev/null; then
        return 0
    fi

    local LOCAL_HASH=$(git hash-object deploy.sh 2>/dev/null || echo "")
    local REMOTE_HASH=$(git show "origin/$CURRENT_BRANCH:deploy.sh" 2>/dev/null | git hash-object --stdin 2>/dev/null || echo "")

    if [ -z "$LOCAL_HASH" ] || [ -z "$REMOTE_HASH" ] || [ "$LOCAL_HASH" = "$REMOTE_HASH" ]; then
        return 0
    fi

    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "🔄 New version of deploy.sh detected!"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    if git checkout "origin/$CURRENT_BRANCH" -- deploy.sh 2>/dev/null; then
        echo "✓ deploy.sh updated successfully"
        echo "🔄 Re-executing with new version..."
        export DEPLOY_SCRIPT_UPDATED=true
        exec bash "$0" "$@"
    fi
}

# Main deployment flow
main() {
    update_deploy_script "$@"

    print_header

    print_info "Starting deployment at $(date)"
    print_info "Branch: $BRANCH"
    print_info "Log file: $LOG_FILE"
    echo

    trap on_error ERR

    sanitize_env_file
    check_app_key
    check_environment
    verify_production_security
    enable_maintenance
    pull_code
    update_dependencies
    backup_database
    cleanup_old_backups
    run_migrations
    run_smart_seeding
    build_assets
    optimize_application
    fix_permissions
    restart_queue
    post_deployment
    disable_maintenance
    health_check

    echo -e "\n${GREEN}╔════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║   ✓ TPIX TRADE Deployment Completed!           ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════╝${NC}\n"

    print_info "Deployment finished at $(date)"
    print_success "Your application is now live!"

    echo -e "\n${CYAN}Deployment Summary:${NC}"
    echo -e "  ${PURPLE}Branch:${NC} $BRANCH"
    echo -e "  ${PURPLE}Time:${NC} $(date)"
    if [ -d .git ]; then
        echo -e "  ${PURPLE}Commit:${NC} $(git rev-parse --short HEAD)"
    fi
    APP_ENV=$(grep "^APP_ENV=" .env 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d '\r\n' | xargs || echo "unknown")
    echo -e "  ${PURPLE}Environment:${NC} $APP_ENV"
    echo -e "  ${PURPLE}Log:${NC} $LOG_FILE"
    echo
}

# Parse arguments
DRY_RUN=0
SKIP_BACKUP=0
FORCE_SEED=0
RESEED=0
while [[ $# -gt 0 ]]; do
    case $1 in
        --branch=*)
            BRANCH="${1#*=}"
            shift
            ;;
        --no-backup|--skip-backup)
            SKIP_BACKUP=1
            shift
            ;;
        --seed)
            FORCE_SEED=1
            shift
            ;;
        --reseed)
            RESEED=1
            shift
            ;;
        --verbose|-v)
            VERBOSE=1
            shift
            ;;
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        --quick|-q)
            SKIP_BACKUP=1
            shift
            ;;
        --help|-h)
            echo "Usage: ./deploy.sh [branch] [options]"
            echo ""
            echo "Options:"
            echo "  --branch=NAME    Specify branch to deploy (default: main)"
            echo "  --no-backup      Skip database backup"
            echo "  --seed           Force run seeders"
            echo "  --reseed         Reset seeder tracking and re-run all seeders"
            echo "  --quick, -q      Quick deploy (skip backup)"
            echo "  --dry-run        Show what would be done without executing"
            echo "  --verbose, -v    Show verbose output"
            echo "  --help, -h       Show this help message"
            echo ""
            exit 0
            ;;
        *)
            BRANCH="$1"
            shift
            ;;
    esac
done

# Dry run mode
if [ $DRY_RUN -eq 1 ]; then
    echo -e "${CYAN}╔════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║         DRY RUN MODE - Preview Only            ║${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "Would execute the following steps:"
    echo "  1. Sanitize .env file"
    echo "  2. Check and generate APP_KEY if missing"
    echo "  3. Check environment"
    echo "  4. Verify production security"
    echo "  5. Enable maintenance mode"
    echo "  6. Pull code from branch: $BRANCH"
    echo "  7. Update dependencies (composer, npm)"
    echo "  8. Backup database"
    echo "  9. Run smart migrations"
    echo "  10. Run smart seeding (with hash tracking)"
    echo "  11. Build frontend assets (npm run build)"
    echo "  12. Optimize application (cache, config, OPcache)"
    echo "  13. Fix permissions"
    echo "  14. Restart queue workers"
    echo "  15. Post-deployment tasks"
    echo "  16. Disable maintenance mode"
    echo "  17. Health check"
    echo ""
    exit 0
fi

# Run deployment
main
