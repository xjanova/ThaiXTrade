# ThaiXTrade - How to Deploy

## 3 Ways to Deploy

### 1. Single Command (Recommended)

```bash
ssh user@your-server
cd /home/admin/domains/tpix.online
./deploy.sh
```

### 2. GitHub Actions (Automatic)

Just push to `main` branch. The CI/CD pipeline will:
1. Run tests (PHP + JavaScript)
2. Check code quality
3. Build assets
4. Auto-deploy to production via SSH
5. Run health check

### 3. GitHub Actions (Manual)

1. Go to repository **Actions** tab
2. Select **Deploy to Production** workflow
3. Click **Run workflow**
4. Select environment and confirm

---

## First-time Server Setup

### Step 1: Clone Repository

```bash
cd /var/www
git clone https://github.com/xjanova/ThaiXTrade.git thaixtrade
cd thaixtrade
```

### Step 2: Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
npm ci
```

### Step 3: Configure Environment

```bash
cp .env.example .env
nano .env  # Edit with your production settings
php artisan key:generate
```

### Step 4: Setup Database

```bash
php artisan migrate --force
```

### Step 5: Build Assets

```bash
npm run build
```

### Step 6: Set Permissions

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### Step 7: Configure Web Server

See `DEPLOYMENT.md` for Nginx/Apache configuration.

### Step 8: Setup Cron & Queue (Optional)

```bash
# Cron
echo "* * * * * cd /home/admin/domains/tpix.online && php artisan schedule:run >> /dev/null 2>&1" | crontab -

# Queue worker (via supervisor)
sudo supervisorctl reread
sudo supervisorctl update
```

---

## Deployment Verification Checklist

After deployment, verify:

- [ ] Website loads without errors
- [ ] Database connection works (`php artisan tinker --execute="DB::connection()->getPdo();"`)
- [ ] Assets load correctly (CSS, JS)
- [ ] Trading pages render
- [ ] API endpoints respond
- [ ] Queue workers running (if applicable)
- [ ] SSL certificate valid
- [ ] Error logs clean (`tail -20 storage/logs/laravel.log`)

---

## Quick Troubleshooting

| Problem | Solution |
|---------|----------|
| 403 Error | `chmod -R 775 storage bootstrap/cache` |
| 500 Error | `tail -50 storage/logs/laravel.log` |
| Assets broken | `npm run build` |
| Cache issues | `php artisan cache:clear && php artisan config:clear` |
| Missing .env | `cp .env.example .env && php artisan key:generate` |
| Permission denied | `chown -R www-data:www-data storage bootstrap/cache` |
