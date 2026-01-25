#!/bin/bash

#############################################
#  ThaiXTrade - Security Audit Script
#  Developed by Xman Studio
#
#  Performs security checks on the application
#############################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

log_pass() { echo -e "  ${GREEN}[PASS]${NC} $1"; }
log_fail() { echo -e "  ${RED}[FAIL]${NC} $1"; }
log_warn() { echo -e "  ${YELLOW}[WARN]${NC} $1"; }
log_info() { echo -e "  ${BLUE}[INFO]${NC} $1"; }

ISSUES=0
WARNINGS=0

echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║              ThaiXTrade Security Audit                         ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

#----------------------------------------
# File & Directory Checks
#----------------------------------------
echo -e "${YELLOW}File & Directory Security${NC}"

# Check .env file permissions
if [ -f ".env" ]; then
    ENV_PERMS=$(stat -c "%a" .env 2>/dev/null || stat -f "%OLp" .env 2>/dev/null)
    if [ "$ENV_PERMS" = "600" ] || [ "$ENV_PERMS" = "640" ]; then
        log_pass ".env file has secure permissions ($ENV_PERMS)"
    else
        log_warn ".env file permissions are $ENV_PERMS (recommended: 600 or 640)"
        WARNINGS=$((WARNINGS + 1))
    fi
else
    log_fail ".env file not found"
    ISSUES=$((ISSUES + 1))
fi

# Check storage directory permissions
if [ -d "storage" ]; then
    STORAGE_PERMS=$(stat -c "%a" storage 2>/dev/null || stat -f "%OLp" storage 2>/dev/null)
    if [ "$STORAGE_PERMS" = "775" ] || [ "$STORAGE_PERMS" = "755" ]; then
        log_pass "storage directory permissions: $STORAGE_PERMS"
    else
        log_warn "storage directory permissions: $STORAGE_PERMS"
        WARNINGS=$((WARNINGS + 1))
    fi
fi

# Check for exposed sensitive files
echo ""
echo -e "${YELLOW}Exposed Files Check${NC}"

EXPOSED_FILES=(".env" ".env.backup" ".env.local" ".git" "composer.json" "package.json" "artisan" ".htaccess")
for file in "${EXPOSED_FILES[@]}"; do
    if [ -f "public_html/$file" ] || [ -d "public_html/$file" ]; then
        log_fail "Sensitive file/directory exposed in public_html: $file"
        ISSUES=$((ISSUES + 1))
    else
        log_pass "$file not exposed in public_html"
    fi
done

#----------------------------------------
# Environment Configuration
#----------------------------------------
echo ""
echo -e "${YELLOW}Environment Configuration${NC}"

if [ -f ".env" ]; then
    # Check APP_DEBUG
    if grep -q "APP_DEBUG=true" .env; then
        log_warn "APP_DEBUG is enabled (should be false in production)"
        WARNINGS=$((WARNINGS + 1))
    else
        log_pass "APP_DEBUG is disabled"
    fi

    # Check APP_ENV
    APP_ENV=$(grep "^APP_ENV=" .env | cut -d'=' -f2)
    if [ "$APP_ENV" = "production" ]; then
        log_pass "APP_ENV is set to production"
    else
        log_warn "APP_ENV is set to '$APP_ENV' (recommended: production)"
        WARNINGS=$((WARNINGS + 1))
    fi

    # Check APP_KEY
    if grep -q "^APP_KEY=$" .env || grep -q "^APP_KEY=base64:$" .env; then
        log_fail "APP_KEY is not set"
        ISSUES=$((ISSUES + 1))
    else
        log_pass "APP_KEY is set"
    fi

    # Check for default/weak credentials
    if grep -qE "(password|secret).*=.*(password|secret|123456|admin)" .env 2>/dev/null; then
        log_warn "Possible weak credentials detected in .env"
        WARNINGS=$((WARNINGS + 1))
    else
        log_pass "No obvious weak credentials in .env"
    fi
fi

#----------------------------------------
# Dependency Security
#----------------------------------------
echo ""
echo -e "${YELLOW}Dependency Security${NC}"

# Check composer audit
if command -v composer &> /dev/null && [ -f "composer.lock" ]; then
    log_info "Running composer audit..."
    if composer audit --no-interaction 2>/dev/null; then
        log_pass "No known PHP vulnerabilities"
    else
        log_warn "Composer audit found issues (run 'composer audit' for details)"
        WARNINGS=$((WARNINGS + 1))
    fi
else
    log_info "Composer not available or no lock file"
fi

# Check npm audit
if command -v npm &> /dev/null && [ -f "package-lock.json" ]; then
    log_info "Running npm audit..."
    AUDIT_RESULT=$(npm audit --json 2>/dev/null | grep -c '"severity":' || echo "0")
    if [ "$AUDIT_RESULT" = "0" ]; then
        log_pass "No known NPM vulnerabilities"
    else
        log_warn "NPM audit found issues (run 'npm audit' for details)"
        WARNINGS=$((WARNINGS + 1))
    fi
else
    log_info "NPM not available or no lock file"
fi

#----------------------------------------
# Web Security Headers
#----------------------------------------
echo ""
echo -e "${YELLOW}Web Security Headers (in .htaccess)${NC}"

if [ -f "public_html/.htaccess" ]; then
    HTACCESS="public_html/.htaccess"

    if grep -q "X-Frame-Options" "$HTACCESS"; then
        log_pass "X-Frame-Options header configured"
    else
        log_warn "X-Frame-Options header not found"
        WARNINGS=$((WARNINGS + 1))
    fi

    if grep -q "X-XSS-Protection" "$HTACCESS"; then
        log_pass "X-XSS-Protection header configured"
    else
        log_warn "X-XSS-Protection header not found"
        WARNINGS=$((WARNINGS + 1))
    fi

    if grep -q "X-Content-Type-Options" "$HTACCESS"; then
        log_pass "X-Content-Type-Options header configured"
    else
        log_warn "X-Content-Type-Options header not found"
        WARNINGS=$((WARNINGS + 1))
    fi

    if grep -q "Content-Security-Policy" "$HTACCESS"; then
        log_pass "Content-Security-Policy header configured"
    else
        log_warn "Content-Security-Policy header not found"
        WARNINGS=$((WARNINGS + 1))
    fi
else
    log_warn "public_html/.htaccess not found"
    WARNINGS=$((WARNINGS + 1))
fi

#----------------------------------------
# SSL/HTTPS Check
#----------------------------------------
echo ""
echo -e "${YELLOW}SSL/HTTPS Configuration${NC}"

if [ -f ".env" ]; then
    APP_URL=$(grep "^APP_URL=" .env | cut -d'=' -f2)
    if [[ "$APP_URL" == https://* ]]; then
        log_pass "APP_URL uses HTTPS"
    else
        log_warn "APP_URL does not use HTTPS: $APP_URL"
        WARNINGS=$((WARNINGS + 1))
    fi
fi

#----------------------------------------
# Summary
#----------------------------------------
echo ""
echo -e "${CYAN}════════════════════════════════════════════════════════════════${NC}"
echo -e "${CYAN}                      Audit Summary                              ${NC}"
echo -e "${CYAN}════════════════════════════════════════════════════════════════${NC}"
echo ""

if [ $ISSUES -eq 0 ] && [ $WARNINGS -eq 0 ]; then
    echo -e "  ${GREEN}All checks passed!${NC}"
    echo ""
    exit 0
else
    if [ $ISSUES -gt 0 ]; then
        echo -e "  ${RED}Critical Issues: $ISSUES${NC}"
    fi
    if [ $WARNINGS -gt 0 ]; then
        echo -e "  ${YELLOW}Warnings: $WARNINGS${NC}"
    fi
    echo ""
    echo -e "  ${YELLOW}Please review and fix the issues above.${NC}"
    echo ""
    exit 1
fi
