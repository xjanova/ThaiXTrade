#!/bin/bash

#############################################
#  ThaiXTrade - Test Runner Script
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
log_success() { echo -e "${GREEN}[PASS]${NC} $1"; }
log_error() { echo -e "${RED}[FAIL]${NC} $1"; }

echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║              ThaiXTrade Test Runner                            ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

FAILED=0

# Run PHP Tests
run_php_tests() {
    log_info "Running PHP tests..."

    if [ -f "vendor/bin/phpunit" ]; then
        if ./vendor/bin/phpunit --colors=always; then
            log_success "PHP tests passed"
        else
            log_error "PHP tests failed"
            FAILED=1
        fi
    elif [ -f "artisan" ]; then
        if php artisan test --colors=always; then
            log_success "PHP tests passed"
        else
            log_error "PHP tests failed"
            FAILED=1
        fi
    else
        log_info "PHPUnit not found, skipping PHP tests"
    fi
}

# Run JavaScript Tests
run_js_tests() {
    log_info "Running JavaScript tests..."

    if [ -f "node_modules/.bin/vitest" ]; then
        if npm run test:run; then
            log_success "JavaScript tests passed"
        else
            log_error "JavaScript tests failed"
            FAILED=1
        fi
    else
        log_info "Vitest not found, skipping JavaScript tests"
    fi
}

# Run Linting
run_linting() {
    log_info "Running code linting..."

    # PHP Linting
    if [ -f "vendor/bin/pint" ]; then
        if ./vendor/bin/pint --test; then
            log_success "PHP linting passed"
        else
            log_error "PHP linting failed (run ./vendor/bin/pint to fix)"
            FAILED=1
        fi
    fi

    # JS Linting (optional)
    if [ -f "node_modules/.bin/eslint" ]; then
        if npm run lint 2>/dev/null; then
            log_success "JavaScript linting passed"
        else
            log_info "JavaScript linting skipped (run npm run lint to fix)"
        fi
    fi
}

# Generate Coverage Report
generate_coverage() {
    log_info "Generating coverage reports..."

    # PHP Coverage
    if [ -f "vendor/bin/phpunit" ]; then
        ./vendor/bin/phpunit --coverage-html coverage-report --coverage-clover coverage.xml 2>/dev/null || true
        if [ -d "coverage-report" ]; then
            log_success "PHP coverage report: coverage-report/index.html"
        fi
    fi

    # JS Coverage
    if [ -f "node_modules/.bin/vitest" ]; then
        npm run test:coverage 2>/dev/null || true
        if [ -d "coverage-js" ]; then
            log_success "JS coverage report: coverage-js/index.html"
        fi
    fi
}

# Show help
show_help() {
    echo "ThaiXTrade Test Runner"
    echo ""
    echo "Usage: $0 [command]"
    echo ""
    echo "Commands:"
    echo "  all         Run all tests (default)"
    echo "  php         Run PHP tests only"
    echo "  js          Run JavaScript tests only"
    echo "  lint        Run code linting"
    echo "  coverage    Generate coverage reports"
    echo "  watch       Run tests in watch mode"
    echo "  help        Show this help"
    echo ""
    echo "Examples:"
    echo "  $0              # Run all tests"
    echo "  $0 php          # Run PHP tests only"
    echo "  $0 coverage     # Run tests with coverage"
}

# Watch mode
watch_tests() {
    log_info "Starting test watcher..."

    if [ -f "node_modules/.bin/vitest" ]; then
        npm run test
    else
        log_error "Vitest not found for watch mode"
    fi
}

# Parse arguments
case "${1:-all}" in
    all)
        run_php_tests
        echo ""
        run_js_tests
        echo ""
        run_linting
        ;;
    php)
        run_php_tests
        ;;
    js|javascript)
        run_js_tests
        ;;
    lint)
        run_linting
        ;;
    coverage)
        run_php_tests
        run_js_tests
        generate_coverage
        ;;
    watch)
        watch_tests
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

echo ""
if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                    All tests passed!                           ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
    exit 0
else
    echo -e "${RED}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${RED}║                    Some tests failed!                          ║${NC}"
    echo -e "${RED}╚════════════════════════════════════════════════════════════════╝${NC}"
    exit 1
fi
