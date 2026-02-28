#!/bin/bash

#############################################
#  TPIX TRADE - Release Script
#  Developed by Xman Studio
#
#  Creates a new release with version bump,
#  changelog update, and git tag
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
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

BUMP_TYPE="${1:-patch}"
VERSION_FILE="version.json"

echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║              TPIX TRADE Release Manager                         ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check for uncommitted changes
if [ -n "$(git status --porcelain)" ]; then
    log_error "You have uncommitted changes. Please commit or stash them first."
    exit 1
fi

# Get current version
if command -v jq &> /dev/null; then
    CURRENT_VERSION=$(jq -r '.version' "$VERSION_FILE")
else
    CURRENT_VERSION=$(grep -o '"version": *"[^"]*"' "$VERSION_FILE" | cut -d'"' -f4)
fi

# Parse version
MAJOR=$(echo "$CURRENT_VERSION" | cut -d. -f1)
MINOR=$(echo "$CURRENT_VERSION" | cut -d. -f2)
PATCH=$(echo "$CURRENT_VERSION" | cut -d. -f3)

# Calculate new version
case $BUMP_TYPE in
    major)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        ;;
    minor)
        MINOR=$((MINOR + 1))
        PATCH=0
        ;;
    patch)
        PATCH=$((PATCH + 1))
        ;;
    *)
        log_error "Invalid bump type: $BUMP_TYPE (use: major, minor, patch)"
        exit 1
        ;;
esac

NEW_VERSION="$MAJOR.$MINOR.$PATCH"
DATE_NOW=$(date +%Y-%m-%d)

echo -e "Current version: ${YELLOW}$CURRENT_VERSION${NC}"
echo -e "New version:     ${GREEN}$NEW_VERSION${NC}"
echo -e "Bump type:       ${CYAN}$BUMP_TYPE${NC}"
echo ""

read -p "Create release v$NEW_VERSION? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    log_info "Release cancelled."
    exit 0
fi

# Step 1: Run tests (if available)
echo ""
log_info "Step 1/6: Running tests..."
if [ -f "phpunit.xml" ] || [ -f "phpunit.xml.dist" ]; then
    if php artisan test --stop-on-failure 2>/dev/null; then
        log_success "Tests passed"
    else
        log_error "Tests failed. Fix tests before release."
        exit 1
    fi
else
    log_info "No tests configured, skipping..."
fi

# Step 2: Update version files
echo ""
log_info "Step 2/6: Updating version files..."

# Update version.json
if command -v jq &> /dev/null; then
    jq --arg v "$NEW_VERSION" --arg d "$DATE_NOW" \
       '.version = $v | .releaseDate = $d | .build = (.build + 1)' \
       "$VERSION_FILE" > "${VERSION_FILE}.tmp" && mv "${VERSION_FILE}.tmp" "$VERSION_FILE"
else
    sed -i "s/\"version\": *\"[^\"]*\"/\"version\": \"$NEW_VERSION\"/" "$VERSION_FILE"
    sed -i "s/\"releaseDate\": *\"[^\"]*\"/\"releaseDate\": \"$DATE_NOW\"/" "$VERSION_FILE"
fi

# Update package.json
if [ -f "package.json" ]; then
    if command -v jq &> /dev/null; then
        jq --arg v "$NEW_VERSION" '.version = $v' package.json > package.json.tmp && mv package.json.tmp package.json
    else
        sed -i "s/\"version\": *\"[^\"]*\"/\"version\": \"$NEW_VERSION\"/" package.json
    fi
fi

log_success "Version files updated"

# Step 3: Update changelog
echo ""
log_info "Step 3/6: Updating changelog..."

# Insert new version section at top of changelog
TEMP_CHANGELOG=$(mktemp)
cat > "$TEMP_CHANGELOG" << EOF
# Changelog

All notable changes to TPIX TRADE will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
-

### Changed
-

### Fixed
-

---

## [$NEW_VERSION] - $DATE_NOW

### Added
- Release v$NEW_VERSION

### Changed
-

### Fixed
-

---

EOF

# Append rest of changelog (skip header)
tail -n +15 CHANGELOG.md >> "$TEMP_CHANGELOG" 2>/dev/null || true
mv "$TEMP_CHANGELOG" CHANGELOG.md

log_success "Changelog updated"
echo -e "${YELLOW}Please edit CHANGELOG.md to add release notes${NC}"

# Step 4: Build for production
echo ""
log_info "Step 4/6: Building for production..."
npm run build
log_success "Production build complete"

# Step 5: Commit changes
echo ""
log_info "Step 5/6: Committing changes..."
git add version.json package.json CHANGELOG.md
git commit -m "chore(release): v$NEW_VERSION

- Bump version to $NEW_VERSION
- Update changelog
- Production build"
log_success "Changes committed"

# Step 6: Create tag
echo ""
log_info "Step 6/6: Creating git tag..."
git tag -a "v$NEW_VERSION" -m "Release v$NEW_VERSION

TPIX TRADE v$NEW_VERSION
Released: $DATE_NOW

See CHANGELOG.md for details."

log_success "Tag v$NEW_VERSION created"

# Summary
echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║            Release v$NEW_VERSION Created!                           ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "Next steps:"
echo -e "  1. Review and edit ${YELLOW}CHANGELOG.md${NC}"
echo -e "  2. Amend commit if needed: ${CYAN}git commit --amend${NC}"
echo -e "  3. Push to remote: ${CYAN}git push && git push --tags${NC}"
echo -e "  4. Create GitHub release (optional)"
echo ""
