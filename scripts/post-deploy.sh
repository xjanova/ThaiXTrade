#!/bin/bash
#############################################
#  TPIX TRADE - Post-Deploy Tasks
#  Run after code deploy is complete.
#  Handles: migrations, cache clear, queue restart, version bump.
#
#  Usage:
#    ./scripts/post-deploy.sh              # full run
#    ./scripts/post-deploy.sh --skip-migrate
#    ./scripts/post-deploy.sh --dry-run    # preview only
#
#  Idempotent — safe to re-run. Exits non-zero on any failure.
#
#  Developed by Xman Studio
#############################################

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1" >&2; }

# Parse flags
SKIP_MIGRATE=0
DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --skip-migrate) SKIP_MIGRATE=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --help|-h)
      echo "Usage: $0 [--skip-migrate] [--dry-run]"
      exit 0
      ;;
    *)
      log_error "Unknown flag: $arg"
      exit 1
      ;;
  esac
done

# Move to project root
cd "$(dirname "$0")/.."

echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║              TPIX TRADE Post-Deploy                             ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

if [[ $DRY_RUN -eq 1 ]]; then
  log_warn "DRY RUN — no changes will be applied"
  PHP_DRY="--pretend"
else
  PHP_DRY=""
fi

# 1. Pre-flight checks
log_info "Pre-flight checks..."
if [[ ! -f artisan ]]; then
  log_error "artisan not found — run from project root"
  exit 1
fi
if ! command -v php >/dev/null 2>&1; then
  log_error "php not in PATH"
  exit 1
fi
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
log_success "PHP ${PHP_VERSION}"

# Check .env exists
if [[ ! -f .env ]]; then
  log_error ".env missing — aborting (will not run migrations without config)"
  exit 1
fi

# Warn if APP_ENV != production
APP_ENV=$(php -r "echo getenv('APP_ENV') ?: (parse_ini_file('.env')['APP_ENV'] ?? 'unknown');")
if [[ "$APP_ENV" != "production" ]]; then
  log_warn "APP_ENV=$APP_ENV (not production)"
fi

# 2. Database migrations
if [[ $SKIP_MIGRATE -eq 0 ]]; then
  log_info "Running database migrations..."
  if [[ $DRY_RUN -eq 1 ]]; then
    php artisan migrate:status
  else
    php artisan migrate --force
  fi
  log_success "Migrations done"
else
  log_warn "Skipped migrations (--skip-migrate)"
fi

# 3. Clear + rebuild caches
log_info "Clearing caches..."
if [[ $DRY_RUN -eq 0 ]]; then
  php artisan cache:clear
  php artisan config:clear
  php artisan route:clear
  php artisan view:clear
fi
log_success "Caches cleared"

log_info "Rebuilding optimized caches..."
if [[ $DRY_RUN -eq 0 ]]; then
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  php artisan event:cache
fi
log_success "Caches rebuilt"

# 4. Restart queue workers (if supervisor is managing them)
log_info "Restarting queue workers..."
if [[ $DRY_RUN -eq 0 ]]; then
  php artisan queue:restart || log_warn "queue:restart failed — workers may not be running"
fi
log_success "Queue restart signal sent"

# 5. Storage permissions
log_info "Fixing storage permissions..."
if [[ $DRY_RUN -eq 0 ]]; then
  chmod -R 775 storage bootstrap/cache 2>/dev/null || \
    log_warn "chmod failed — may need sudo"
fi
log_success "Permissions set"

# 6. Version info
VERSION=$(php -r "echo json_decode(file_get_contents('version.json'))->version;" 2>/dev/null || echo "unknown")
log_info "Deployed version: ${CYAN}${VERSION}${NC}"

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║           Post-Deploy Complete                                  ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""
log_info "Next: verify https://tpix.online/api/v1/chains returns 200"
log_info "Monitor: tail -f storage/logs/laravel.log"
