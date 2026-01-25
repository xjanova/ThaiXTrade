#!/bin/bash

#############################################
#  ThaiXTrade - Version Bump Script
#  Developed by Xman Studio
#
#  Usage: ./scripts/bump-version.sh [major|minor|patch|build]
#############################################

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

VERSION_FILE="version.json"
CHANGELOG_FILE="CHANGELOG.md"

# Check if version.json exists
if [ ! -f "$VERSION_FILE" ]; then
    echo -e "${RED}Error: $VERSION_FILE not found!${NC}"
    exit 1
fi

# Check if jq is installed
if ! command -v jq &> /dev/null; then
    echo -e "${YELLOW}Warning: jq not found. Using fallback method.${NC}"
    USE_JQ=false
else
    USE_JQ=true
fi

# Get current version
get_current_version() {
    if [ "$USE_JQ" = true ]; then
        jq -r '.version' "$VERSION_FILE"
    else
        grep -o '"version": *"[^"]*"' "$VERSION_FILE" | cut -d'"' -f4
    fi
}

# Get current build
get_current_build() {
    if [ "$USE_JQ" = true ]; then
        jq -r '.build' "$VERSION_FILE"
    else
        grep -o '"build": *[0-9]*' "$VERSION_FILE" | grep -o '[0-9]*'
    fi
}

# Parse version
parse_version() {
    local version=$1
    MAJOR=$(echo "$version" | cut -d. -f1)
    MINOR=$(echo "$version" | cut -d. -f2)
    PATCH=$(echo "$version" | cut -d. -f3)
}

# Update version.json
update_version_file() {
    local new_version=$1
    local new_build=$2
    local date_now=$(date +%Y-%m-%d)

    if [ "$USE_JQ" = true ]; then
        jq --arg v "$new_version" --arg b "$new_build" --arg d "$date_now" \
           '.version = $v | .build = ($b | tonumber) | .releaseDate = $d' \
           "$VERSION_FILE" > "${VERSION_FILE}.tmp" && mv "${VERSION_FILE}.tmp" "$VERSION_FILE"
    else
        # Fallback: use sed
        sed -i "s/\"version\": *\"[^\"]*\"/\"version\": \"$new_version\"/" "$VERSION_FILE"
        sed -i "s/\"build\": *[0-9]*/\"build\": $new_build/" "$VERSION_FILE"
        sed -i "s/\"releaseDate\": *\"[^\"]*\"/\"releaseDate\": \"$date_now\"/" "$VERSION_FILE"
    fi
}

# Update package.json
update_package_json() {
    local new_version=$1
    if [ -f "package.json" ]; then
        if [ "$USE_JQ" = true ]; then
            jq --arg v "$new_version" '.version = $v' package.json > package.json.tmp && mv package.json.tmp package.json
        else
            sed -i "s/\"version\": *\"[^\"]*\"/\"version\": \"$new_version\"/" package.json
        fi
        echo -e "${GREEN}Updated package.json${NC}"
    fi
}

# Update composer.json
update_composer_json() {
    local new_version=$1
    if [ -f "composer.json" ]; then
        # composer.json might not have version field, skip if not present
        if grep -q '"version"' composer.json 2>/dev/null; then
            if [ "$USE_JQ" = true ]; then
                jq --arg v "$new_version" '.version = $v' composer.json > composer.json.tmp && mv composer.json.tmp composer.json
            else
                sed -i "s/\"version\": *\"[^\"]*\"/\"version\": \"$new_version\"/" composer.json
            fi
            echo -e "${GREEN}Updated composer.json${NC}"
        fi
    fi
}

# Generate changelog entry
generate_changelog_entry() {
    local version=$1
    local date_now=$(date +%Y-%m-%d)
    local temp_file=$(mktemp)

    echo "## [$version] - $date_now" > "$temp_file"
    echo "" >> "$temp_file"
    echo "### Added" >> "$temp_file"
    echo "- " >> "$temp_file"
    echo "" >> "$temp_file"
    echo "### Changed" >> "$temp_file"
    echo "- " >> "$temp_file"
    echo "" >> "$temp_file"
    echo "### Fixed" >> "$temp_file"
    echo "- " >> "$temp_file"
    echo "" >> "$temp_file"

    if [ -f "$CHANGELOG_FILE" ]; then
        # Insert after header
        head -n 10 "$CHANGELOG_FILE" > "${CHANGELOG_FILE}.new"
        cat "$temp_file" >> "${CHANGELOG_FILE}.new"
        tail -n +11 "$CHANGELOG_FILE" >> "${CHANGELOG_FILE}.new"
        mv "${CHANGELOG_FILE}.new" "$CHANGELOG_FILE"
    fi

    rm "$temp_file"
}

# Create git tag
create_git_tag() {
    local version=$1
    local message=$2

    read -p "Create git tag v$version? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        git tag -a "v$version" -m "${message:-Release v$version}"
        echo -e "${GREEN}Created tag: v$version${NC}"

        read -p "Push tag to remote? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            git push origin "v$version"
            echo -e "${GREEN}Pushed tag to remote${NC}"
        fi
    fi
}

# Main bump function
bump_version() {
    local bump_type=$1
    local current_version=$(get_current_version)
    local current_build=$(get_current_build)

    parse_version "$current_version"

    case $bump_type in
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
        build)
            # Only increment build number
            current_build=$((current_build + 1))
            update_version_file "$current_version" "$current_build"
            echo -e "${GREEN}Build bumped: $current_build${NC}"
            return
            ;;
        *)
            echo -e "${RED}Invalid bump type: $bump_type${NC}"
            echo "Usage: $0 [major|minor|patch|build]"
            exit 1
            ;;
    esac

    local new_version="$MAJOR.$MINOR.$PATCH"
    local new_build=$((current_build + 1))

    echo -e "${CYAN}╔════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║      ThaiXTrade Version Bump           ║${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "Current version: ${YELLOW}$current_version${NC} (build $current_build)"
    echo -e "New version:     ${GREEN}$new_version${NC} (build $new_build)"
    echo ""

    read -p "Proceed with version bump? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}Aborted.${NC}"
        exit 0
    fi

    # Update files
    update_version_file "$new_version" "$new_build"
    echo -e "${GREEN}Updated version.json${NC}"

    update_package_json "$new_version"
    update_composer_json "$new_version"

    # Generate changelog entry
    if [ -f "$CHANGELOG_FILE" ]; then
        read -p "Generate changelog entry? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            generate_changelog_entry "$new_version"
            echo -e "${GREEN}Updated CHANGELOG.md${NC}"
            echo -e "${YELLOW}Please edit CHANGELOG.md to add your changes${NC}"
        fi
    fi

    # Git operations
    read -p "Commit version bump? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        git add version.json package.json composer.json CHANGELOG.md 2>/dev/null || true
        git commit -m "chore: bump version to $new_version"
        echo -e "${GREEN}Committed version bump${NC}"

        create_git_tag "$new_version"
    fi

    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║    Version bump complete!              ║${NC}"
    echo -e "${GREEN}║    New version: $new_version                  ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════╝${NC}"
}

# Show current version
show_version() {
    local version=$(get_current_version)
    local build=$(get_current_build)
    echo -e "${CYAN}ThaiXTrade${NC} v${GREEN}$version${NC} (build ${YELLOW}$build${NC})"
}

# Show help
show_help() {
    echo "ThaiXTrade Version Bump Script"
    echo ""
    echo "Usage: $0 [command]"
    echo ""
    echo "Commands:"
    echo "  major    Bump major version (1.0.0 -> 2.0.0)"
    echo "  minor    Bump minor version (1.0.0 -> 1.1.0)"
    echo "  patch    Bump patch version (1.0.0 -> 1.0.1)"
    echo "  build    Bump build number only"
    echo "  show     Show current version"
    echo "  help     Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 patch     # 1.0.0 -> 1.0.1"
    echo "  $0 minor     # 1.0.0 -> 1.1.0"
    echo "  $0 major     # 1.0.0 -> 2.0.0"
}

# Parse arguments
case "${1:-show}" in
    major|minor|patch|build)
        bump_version "$1"
        ;;
    show|version|-v|--version)
        show_version
        ;;
    help|-h|--help)
        show_help
        ;;
    *)
        echo -e "${RED}Unknown command: $1${NC}"
        show_help
        exit 1
        ;;
esac
