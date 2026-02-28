# TPIX TRADE - Deployment Guide

## Quick Start

### Single Command Deployment (Recommended)

```bash
# SSH into your server
ssh user@your-server

# Navigate to project
cd /home/admin/domains/tpix.online

# Run deployment
./deploy.sh
```

### First-time Setup

```bash
# Clone the repository
git clone https://github.com/xjanova/ThaiXTrade.git /home/admin/domains/tpix.online
cd /home/admin/domains/tpix.online

# Run installation
./install.sh

# Or manually:
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
chmod -R 775 storage bootstrap/cache
```

---

## Deployment Methods

### Method 1: Automated Script (Recommended)

```bash
./deploy.sh                        # Full deployment
./deploy.sh --quick                # Quick deployment (skip backup)
./deploy.sh --branch=develop       # Deploy specific branch
./deploy.sh --dry-run              # Preview without executing
./deploy.sh --seed                 # Force run seeders
./deploy.sh --reseed               # Reset seeder tracking
```

### Method 2: GitHub Actions (Automatic)

Push to `main` branch triggers:
1. CI tests run automatically
2. If tests pass, auto-deploy to production via SSH
3. Health check verifies deployment

### Method 3: GitHub Actions (Manual)

1. Go to **Actions** tab in GitHub
2. Select **Deploy to Production**
3. Click **Run workflow**
4. Choose environment (production/staging)

### Method 4: Makefile Commands

```bash
make deploy          # Full deployment
make deploy-quick    # Quick deployment
make backup          # Create backup
make rollback        # Rollback to previous version
```

---

## deploy.sh Features

- **Smart Migrations**: Auto-creates migration table, handles existing tables
- **Smart Seeding**: Tracks seeder changes via MD5 hashes, only runs new/changed seeders
- **Auto Rollback**: Automatic rollback on failure
- **Environment Sanitization**: Fixes common .env issues
- **Security Verification**: Checks APP_DEBUG, Telescope, Debugbar in production
- **Health Check**: Database connectivity + HTTP response verification
- **Detailed Logging**: Full logs in `storage/logs/deploy/`
- **Web Server Restart**: Detects PHP-FPM, Nginx, Apache, DirectAdmin, cPanel

---

## Server Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| PHP | 8.2 | 8.3 |
| Node.js | 18 | 20 |
| Composer | 2.x | Latest |
| MySQL | 8.0 | 8.0+ |
| RAM | 1 GB | 2 GB+ |
| Disk | 1 GB | 5 GB+ |

### Required PHP Extensions

```
dom, curl, libxml, mbstring, zip, pcntl, pdo, pdo_mysql, pdo_sqlite,
bcmath, soap, intl, gd, exif, iconv, openssl, tokenizer, xml, ctype, json
```

---

## Nginx Configuration

```nginx
server {
    listen 80;
    server_name tpix.online;
    root /home/admin/domains/tpix.online/public_html;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

**Important**: TPIX TRADE uses `public_html/` as the document root (not `public/`).

---

## Supervisor Configuration (Queue Worker)

```ini
[program:tpixtrade-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/admin/domains/tpix.online/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/home/admin/domains/tpix.online/storage/logs/worker.log
stopwaitsecs=3600
```

---

## Cron Job (Laravel Scheduler)

```bash
* * * * * cd /home/admin/domains/tpix.online && php artisan schedule:run >> /dev/null 2>&1
```

---

## Troubleshooting

### 403 Forbidden

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 500 Internal Server Error

```bash
# Check logs
tail -50 storage/logs/laravel.log

# Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### Composer Install Fails

```bash
composer install --no-scripts
composer dump-autoload
```

### Assets Not Loading

```bash
# Rebuild assets
npm run build

# Check public_html/build/ exists
ls -la public_html/build/
```

---

## Security Checklist

- [ ] `APP_DEBUG=false` in production
- [ ] `APP_ENV=production`
- [ ] Strong `APP_KEY` generated
- [ ] HTTPS configured with SSL certificate
- [ ] Database credentials secured
- [ ] File permissions correct (775 for storage, 755 for others)
- [ ] Web server configured to deny access to `.env` and `.git`
- [ ] Rate limiting configured
- [ ] CORS properly configured

---

## Environment Variables

See `.env.example` for all available configuration options.

Key production settings:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tpix.online
APP_TIMEZONE=Asia/Bangkok

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=thaixtrade
DB_USERNAME=your_user
DB_PASSWORD=your_password

SESSION_DRIVER=database
SESSION_ENCRYPT=true
CACHE_STORE=file
QUEUE_CONNECTION=database
```
