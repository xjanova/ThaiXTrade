#!/bin/bash
# Update production .env for MySQL + Redis
# Run on server: bash scripts/update-env-production.sh

set -e

ENV_FILE=".env"

if [ ! -f "$ENV_FILE" ]; then
    echo "ERROR: .env file not found"
    exit 1
fi

echo "=== Updating .env for MySQL + Redis ==="

# Backup current .env
cp "$ENV_FILE" "${ENV_FILE}.backup.$(date +%Y%m%d%H%M%S)"

# Update DB settings
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' "$ENV_FILE"
sed -i 's/^DB_HOST=.*/DB_HOST=localhost/' "$ENV_FILE"
sed -i 's/^DB_PORT=.*/DB_PORT=3306/' "$ENV_FILE"
sed -i 's/^DB_DATABASE=.*/DB_DATABASE=admin_tpix/' "$ENV_FILE"
sed -i 's/^DB_USERNAME=.*/DB_USERNAME=admin_tpix/' "$ENV_FILE"
# Password must be set manually for security:
# sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=YOUR_PASSWORD/' "$ENV_FILE"

# Update to Redis
sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=redis/' "$ENV_FILE"
sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=redis/' "$ENV_FILE"
sed -i 's/^CACHE_STORE=.*/CACHE_STORE=redis/' "$ENV_FILE"
sed -i 's/^REDIS_CLIENT=.*/REDIS_CLIENT=phpredis/' "$ENV_FILE"

echo ""
echo "=== Updated settings ==="
grep -E "^DB_|^SESSION_DRIVER|^QUEUE_CONNECTION|^CACHE_STORE|^REDIS_CLIENT" "$ENV_FILE"
echo ""
echo "⚠️  IMPORTANT: Set DB_PASSWORD manually!"
echo "    Run: sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=YOUR_PASSWORD/' .env"
echo ""
echo "Then run: php artisan config:clear && php artisan migrate --force"
