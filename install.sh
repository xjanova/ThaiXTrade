#!/bin/bash

#############################################
#  ThaiXTrade - Installation Script
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

# Banner
print_banner() {
    echo -e "${CYAN}"
    echo "╔═══════════════════════════════════════════════════════════════╗"
    echo "║                                                               ║"
    echo "║   ████████╗██╗  ██╗ █████╗ ██╗██╗  ██╗████████╗██████╗  █████╗ ██████╗ ███████╗  ║"
    echo "║   ╚══██╔══╝██║  ██║██╔══██╗██║╚██╗██╔╝╚══██╔══╝██╔══██╗██╔══██╗██╔══██╗██╔════╝  ║"
    echo "║      ██║   ███████║███████║██║ ╚███╔╝    ██║   ██████╔╝███████║██║  ██║█████╗    ║"
    echo "║      ██║   ██╔══██║██╔══██║██║ ██╔██╗    ██║   ██╔══██╗██╔══██║██║  ██║██╔══╝    ║"
    echo "║      ██║   ██║  ██║██║  ██║██║██╔╝ ██╗   ██║   ██║  ██║██║  ██║██████╔╝███████╗  ║"
    echo "║      ╚═╝   ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝╚═╝  ╚═╝   ╚═╝   ╚═╝  ╚═╝╚═╝  ╚═╝╚═════╝ ╚══════╝  ║"
    echo "║                                                               ║"
    echo "║          Decentralized Exchange Platform v1.0.0               ║"
    echo "║                  Developed by Xman Studio                     ║"
    echo "║                                                               ║"
    echo "╚═══════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check system requirements
check_requirements() {
    log_info "Checking system requirements..."

    # Check PHP
    if ! command -v php &> /dev/null; then
        log_error "PHP is not installed. Please install PHP 8.2 or higher."
        exit 1
    fi

    PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
    if [[ $(echo "$PHP_VERSION < 8.2" | bc -l) -eq 1 ]]; then
        log_error "PHP 8.2 or higher is required. Current version: $PHP_VERSION"
        exit 1
    fi
    log_success "PHP $PHP_VERSION detected"

    # Check Composer
    if ! command -v composer &> /dev/null; then
        log_warning "Composer not found. Installing Composer..."
        curl -sS https://getcomposer.org/installer | php
        sudo mv composer.phar /usr/local/bin/composer
    fi
    log_success "Composer detected"

    # Check Node.js
    if ! command -v node &> /dev/null; then
        log_error "Node.js is not installed. Please install Node.js 18 or higher."
        exit 1
    fi

    NODE_VERSION=$(node -v | cut -d'v' -f2 | cut -d'.' -f1)
    if [[ $NODE_VERSION -lt 18 ]]; then
        log_error "Node.js 18 or higher is required. Current version: $(node -v)"
        exit 1
    fi
    log_success "Node.js $(node -v) detected"

    # Check NPM
    if ! command -v npm &> /dev/null; then
        log_error "NPM is not installed."
        exit 1
    fi
    log_success "NPM $(npm -v) detected"

    # Check for optional Redis
    if command -v redis-cli &> /dev/null; then
        log_success "Redis detected (optional)"
    else
        log_warning "Redis not found. Queue and caching will use database driver."
    fi

    log_success "All requirements satisfied!"
}

# Setup environment
setup_environment() {
    log_info "Setting up environment..."

    if [ ! -f .env ]; then
        if [ -f .env.example ]; then
            cp .env.example .env
            log_success "Created .env file from .env.example"
        else
            log_error ".env.example not found!"
            exit 1
        fi
    else
        log_warning ".env file already exists. Skipping..."
    fi
}

# Install PHP dependencies
install_php_dependencies() {
    log_info "Installing PHP dependencies..."

    composer install --no-interaction --prefer-dist --optimize-autoloader

    log_success "PHP dependencies installed!"
}

# Install Node.js dependencies
install_node_dependencies() {
    log_info "Installing Node.js dependencies..."

    npm ci --prefer-offline || npm install

    log_success "Node.js dependencies installed!"
}

# Generate application key
generate_key() {
    log_info "Generating application key..."

    php artisan key:generate --force

    log_success "Application key generated!"
}

# Setup database
setup_database() {
    log_info "Setting up database..."

    # Create SQLite database if using SQLite
    if grep -q "DB_CONNECTION=sqlite" .env; then
        touch database/database.sqlite
        log_info "SQLite database created"
    fi

    php artisan migrate --force

    log_success "Database migrations completed!"
}

# Build frontend assets
build_assets() {
    log_info "Building frontend assets..."

    npm run build

    log_success "Frontend assets built!"
}

# Set permissions
set_permissions() {
    log_info "Setting file permissions..."

    chmod -R 755 storage bootstrap/cache
    chmod -R 775 storage/logs

    if [ -d "public/build" ]; then
        chmod -R 755 public/build
    fi

    log_success "Permissions set!"
}

# Create storage link
create_storage_link() {
    log_info "Creating storage link..."

    php artisan storage:link --force

    log_success "Storage link created!"
}

# Clear and optimize
optimize_application() {
    log_info "Optimizing application..."

    php artisan config:clear
    php artisan cache:clear
    php artisan view:clear
    php artisan route:clear

    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    log_success "Application optimized!"
}

# Seed database (optional)
seed_database() {
    read -p "Do you want to seed the database with sample data? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        log_info "Seeding database..."
        php artisan db:seed --force
        log_success "Database seeded!"
    fi
}

# Print completion message
print_completion() {
    echo ""
    echo -e "${GREEN}╔═══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                                                               ║${NC}"
    echo -e "${GREEN}║          ThaiXTrade Installation Complete!                    ║${NC}"
    echo -e "${GREEN}║                                                               ║${NC}"
    echo -e "${GREEN}╠═══════════════════════════════════════════════════════════════╣${NC}"
    echo -e "${GREEN}║                                                               ║${NC}"
    echo -e "${GREEN}║  To start the development server:                             ║${NC}"
    echo -e "${GREEN}║    php artisan serve                                          ║${NC}"
    echo -e "${GREEN}║                                                               ║${NC}"
    echo -e "${GREEN}║  For hot-reload development:                                  ║${NC}"
    echo -e "${GREEN}║    npm run dev                                                ║${NC}"
    echo -e "${GREEN}║                                                               ║${NC}"
    echo -e "${GREEN}║  For production with Octane:                                  ║${NC}"
    echo -e "${GREEN}║    php artisan octane:start                                   ║${NC}"
    echo -e "${GREEN}║                                                               ║${NC}"
    echo -e "${GREEN}║  Default URL: http://localhost:8000                           ║${NC}"
    echo -e "${GREEN}║                                                               ║${NC}"
    echo -e "${GREEN}╚═══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# Main installation flow
main() {
    print_banner

    echo ""
    log_info "Starting ThaiXTrade installation..."
    echo ""

    check_requirements
    setup_environment
    install_php_dependencies
    install_node_dependencies
    generate_key
    setup_database
    build_assets
    set_permissions
    create_storage_link
    optimize_application
    seed_database

    print_completion
}

# Run main function
main "$@"
