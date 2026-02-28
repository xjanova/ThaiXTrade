#!/bin/bash

#############################################
#  TPIX TRADE - Setup Git Hooks
#  Developed by Xman Studio
#############################################

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}Setting up Git hooks...${NC}"

# Check if .git directory exists
if [ ! -d ".git" ]; then
    echo -e "${YELLOW}Not a git repository. Skipping hooks setup.${NC}"
    exit 0
fi

# Create hooks directory if not exists
mkdir -p .git/hooks

# Copy hooks
for hook in scripts/hooks/*; do
    if [ -f "$hook" ]; then
        hook_name=$(basename "$hook")
        cp "$hook" ".git/hooks/$hook_name"
        chmod +x ".git/hooks/$hook_name"
        echo -e "${GREEN}Installed: $hook_name${NC}"
    fi
done

echo -e "${GREEN}Git hooks installed successfully!${NC}"
echo ""
echo "Installed hooks:"
echo "  - pre-commit: Code formatting + build increment"
echo "  - post-merge: Auto install dependencies"
